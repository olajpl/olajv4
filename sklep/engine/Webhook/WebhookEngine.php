<?php
// engine/Webhook/WebhookEngine.php — Olaj V4 (robust, zgodny z Twoim starym działającym flow)
// - Idempotencja: fb_webhook_events (INSERT IGNORE + hit_count)
// - Zapis przychodzących do messages (direction='in', channel='messenger', status='pending')
// - Mapowanie PSID→client_id z ochroną przed FK 1452 (NULL gdy brak klienta)
// - Parser: ParserEngine::handleMessengerText (to on wywoła Handlery; Handlery używają Cw)
// - Zero twardych wysyłek w webhooku (brak curl/cw tutaj) — odpowiedź robi handler
// - Drobne logi diagnostyczne (webhook.*, parser.*)

declare(strict_types=1);

namespace Engine\Webhook;

use PDO;
use Throwable;

// Jeśli endpoint nie ma autoloadera, można podpiąć minimalne require:
@require_once __DIR__ . '/../../includes/log.php';
// Parser (żeby nie było Class not found w środowiskach bez autoload)
@require_once __DIR__ . '/../Parser/ParserEngine.php';

final class WebhookEngine
{
    private PDO $pdo;
    private int $ownerId;

    public function __construct(PDO $pdo, int $ownerId)
    {
        $this->pdo     = $pdo;
        $this->ownerId = $ownerId;
    }

    /** GET verify (FB handshake) */
    public function handleVerify(array $get): void
    {
        $mode      = $get['hub_mode'] ?? $get['hub.mode'] ?? null;
        $tokenIn   = $get['hub_verify_token'] ?? $get['hub.verify_token'] ?? null;
        $challenge = $get['hub_challenge'] ?? $get['hub.challenge'] ?? null;

        $this->safeLog('info', 'webhook', 'verify.enter', ['mode' => $mode]);

        if ($mode !== 'subscribe' || $tokenIn === null) {
            http_response_code(400);
            echo 'bad_request';
            return;
        }

        $tokenExpected = $this->getVerifyToken($this->ownerId);
        if (!$tokenExpected) {
            http_response_code(500);
            echo 'no_token';
            return;
        }

        if (hash_equals((string)$tokenExpected, (string)$tokenIn)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo (string)$challenge;
            return;
        }

        http_response_code(403);
        echo 'forbidden';
    }

    /** POST ingest (DM) */
    public function handlePost(string $rawBody, array $headers = []): void
    {
        $rid = 'rq-' . bin2hex(random_bytes(4));
        $this->safeLog('info', 'webhook', 'post.received', ['rid' => $rid, 'len' => strlen($rawBody)]);

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || empty($payload['entry'])) {
            $this->safeLog('warning', 'webhook', 'post.bad_payload', ['rid' => $rid]);
            http_response_code(400);
            echo "bad payload";
            return;
        }

        foreach ($payload['entry'] as $entry) {
            try {
                $this->processEntry($entry, $rawBody, $headers, $rid);
            } catch (Throwable $e) {
                $this->safeLog('error', 'webhook', 'entry.fail', [
                    'rid' => $rid,
                    '_ex_message' => $e->getMessage(),
                    '_ex_file' => $e->getFile(),
                    '_ex_line' => $e->getLine()
                ]);
            }
        }

        http_response_code(200);
        echo "EVENT_RECEIVED";
    }

    private function processEntry(array $entry, string $rawBody, array $headers, string $rid): void
    {
        // FB ms → sekundy
        $entryTimeMs = (int)($entry['time'] ?? 0);
        $eventTimeS  = $this->msToSec($entryTimeMs);
        $entryTimeDT = $eventTimeS ? date('Y-m-d H:i:s', $eventTimeS) : null;

        // page_id — preferuj entry.id, potem recipient.id, fallback ''
        $fallbackPage = '';
        $pageId = (string)($entry['id'] ?? ($entry['messaging'][0]['recipient']['id'] ?? $fallbackPage));

        $this->safeLog('debug', 'webhook', 'entry.meta', [
            'rid' => $rid,
            'page_id' => $pageId,
            'event_time_s' => $eventTimeS,
            'entry_time_dt' => $entryTimeDT
        ]);

        foreach (($entry['messaging'] ?? []) as $mi => $msg) {
            $psid  = $msg['sender']['id']      ?? null;
            $mid   = $msg['message']['mid']    ?? null;
            $text  = (string)($msg['message']['text'] ?? '');
            $deliv = $msg['delivery']['mid']   ?? null;

            // postback → traktuj jako tekst (payload)
            if ($text === '' && !empty($msg['postback']['payload'])) {
                $text = (string)$msg['postback']['payload'];
            }

            $this->safeLog('debug', 'webhook', 'msg.extract', [
                'rid' => $rid,
                'mi' => $mi,
                'psid' => $psid,
                'mid' => $mid,
                'has_text' => $text !== '',
                'text_120' => mb_substr($text, 0, 120)
            ]);

            if (!$psid || !$mid) {
                $this->safeLog('warning', 'webhook', 'msg.skip.missing_ids', ['rid' => $rid, 'mi' => $mi]);
                continue;
            }

            // 1) INSERT IGNORE do fb_webhook_events → NEW/DUP
            $status = $this->insertEvent(
                pageId: $pageId,
                mid: $mid,
                deliveryId: $deliv,
                rawBody: $rawBody,
                headers: $headers,
                eventTimeS: $eventTimeS,
                entryTimeDT: $entryTimeDT
            );
            $this->safeLog('debug', 'webhook', 'event.upsert', ['rid' => $rid, 'mid' => $mid, 'status' => $status]);

            // 2) PSID → client_id (twórz jeśli brak); może zwrócić 0
            $clientId = $this->mapPlatformToClientId('messenger', $psid, $pageId);
            $this->safeLog('debug', 'webhook', 'client.ok', ['rid' => $rid, 'client_id' => $clientId]);

            // 3) messages tylko przy NEW — z walidacją istnienia klienta
            if ($status === 'NEW') {
                $this->insertMessage($clientId > 0 ? $clientId : null, $psid, $mid, $text, $pageId);
                $this->safeLog('info', 'webhook', 'message.saved', ['rid' => $rid, 'mid' => $mid]);
            } else {
                $this->safeLog('debug', 'webhook', 'message.skip.dup', ['rid' => $rid, 'mid' => $mid]);
            }

            // 4) Parser — zawsze (NEW i DUP); klient jako int (0 dozwolone)
            try {
                // Tu zaczyna się magia — handler "daj" sam wyśle DM przez Cw.
                \Engine\Parser\ParserEngine::handleMessengerText($this->pdo, $this->ownerId, (int)$clientId, $text);
                $this->safeLog('info', 'webhook', 'parser.ok', ['rid' => $rid, 'mid' => $mid]);
            } catch (Throwable $e) {
                $this->safeLog('error', 'webhook', 'parser.fail', [
                    'rid' => $rid,
                    '_ex_message' => $e->getMessage(),
                    '_ex_file' => $e->getFile(),
                    '_ex_line' => $e->getLine(),
                    'mid' => $mid,
                    'psid' => $psid
                ]);
            }
        }
    }

    /**
     * Insert/Upsert do fb_webhook_events.
     * Zwraca 'NEW' (insert) lub 'DUP' (duplikat).
     *
     * Zakładamy, że masz unikat po (owner_id, mid) albo po samym mid.
     */
    private function insertEvent(
        string $pageId,
        string $mid,
        ?string $deliveryId,
        string $rawBody,
        array $headers,
        int $eventTimeS,
        ?string $entryTimeDT
    ): string {
        // 1) INSERT IGNORE
        $ins = $this->pdo->prepare("
            INSERT IGNORE INTO fb_webhook_events
            (owner_id, page_id, delivery_id, provider,
             raw_body, headers_json, hit_count,
             last_seen, received_at, mid,
             entry_time, event_time, raw_payload)
            VALUES (:oid, :pid, :did, 'facebook',
                    :raw, :hdr, 1,
                    NOW(), NOW(), :mid,
                    :etime, :evt, :payload)
        ");
        $ins->execute([
            ':oid'     => (int)$this->ownerId,
            ':pid'     => (string)$pageId,
            ':did'     => $deliveryId,
            ':raw'     => $rawBody,
            ':hdr'     => json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':mid'     => $mid,
            ':etime'   => $entryTimeDT,
            ':evt'     => (int)$eventTimeS,
            ':payload' => $rawBody,
        ]);

        if ($ins->rowCount() === 1) {
            return 'NEW';
        }

        // 2) DUP → zwiększ hit_count + last_seen
        $upd = $this->pdo->prepare("
            UPDATE fb_webhook_events
            SET hit_count = hit_count + 1,
                last_seen = NOW()
            WHERE " . $this->eventWhere() . " LIMIT 1
        ");
        $upd->execute($this->eventWhereParams($mid));
        return 'DUP';
    }

    /** Insert do messages — z walidacją klienta i NULL fallback (żeby nie złapać FK 1452) */
    private function insertMessage($clientIdMixed, string $psid, string $mid, string $text, string $pageId): void
    {
        $cid = $this->normalizeClientId($clientIdMixed);

        // sanity: sprawdź czy taki klient istnieje
        if ($cid !== null && !$this->clientExists($cid)) {
            $this->safeLog('warning', 'webhook', 'message.client_orphan', [
                'mid' => $mid,
                'client_id_input' => $clientIdMixed,
                'client_id_checked' => $cid
            ]);
            $cid = null; // unikamy 1452 FK
        }

        try {
            $sql = "INSERT INTO messages
                    (owner_id, client_id, direction, channel,
                     sender_type, platform, platform_user_id,
                     platform_msg_id, status, content, metadata, created_at)
                    VALUES (:oid, :cid, 'in', 'messenger',
                            'client', 'facebook', :psid,
                            :mid, 'pending', :content, :meta, NOW())";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':oid', (int)$this->ownerId, PDO::PARAM_INT);
            if ($cid === null) {
                $stmt->bindValue(':cid', null, PDO::PARAM_NULL);
                $this->safeLog('warning', 'webhook', 'message.client_null', [
                    'psid' => $psid,
                    'mid' => $mid,
                    'page_id' => $pageId
                ]);
            } else {
                $stmt->bindValue(':cid', $cid, PDO::PARAM_INT);
            }
            $stmt->bindValue(':psid', $psid);
            $stmt->bindValue(':mid', $mid);
            $stmt->bindValue(':content', $text);
            $stmt->bindValue(':meta', json_encode(['page_id' => $pageId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $stmt->execute();
        } catch (Throwable $e) {
            $this->safeLog('error', 'webhook', 'message.insert_fail', [
                '_ex_message' => $e->getMessage(),
                '_ex_file' => $e->getFile(),
                '_ex_line' => $e->getLine(),
                'mid' => $mid,
                'client_id_after_check' => $cid
            ]);
        }
    }

    /**
     * PSID (platform_user_id) → client_id:
     * - Szukamy w client_platform_ids po (owner_id, platform IN ('facebook','messenger'), platform_user_id)
     * - Jeśli brak → tworzymy klienta + mapping (transakcja).
     * - Jeśli tabela ma kolumnę page_id, ustawiamy ją ('' gdy NOT NULL i brak wartości).
     * Zwraca int (0 = brak klienta).
     */
    private function mapPlatformToClientId(string $platform, string $platformUserId, ?string $pageId = null): int
    {
        // 1) Istniejący mapping?
        $q = $this->pdo->prepare(
            "SELECT client_id FROM client_platform_ids
             WHERE owner_id=:oid
               AND platform IN ('facebook','messenger')
               AND platform_user_id=:puid
             LIMIT 1"
        );
        $q->execute([':oid' => $this->ownerId, ':puid' => $platformUserId]);
        $cid = (int)($q->fetchColumn() ?: 0);
        if ($cid > 0) {
            // uzupełnij page_id jeśli kolumna istnieje
            $meta = $this->columns('client_platform_ids');
            if (isset($meta['page_id'])) {
                $value = $pageId;
                if ($meta['page_id']['Null'] === 'NO' && $value === null) {
                    $value = '';
                }
                $upd = $this->pdo->prepare(
                    "UPDATE client_platform_ids
                     SET page_id=:pid
                     WHERE owner_id=:oid
                       AND platform IN ('facebook','messenger')
                       AND platform_user_id=:puid
                     LIMIT 1"
                );
                $upd->execute([':pid' => $value, ':oid' => $this->ownerId, ':puid' => $platformUserId]);
            }
            // sanity: czy klient istnieje?
            if ($this->clientExists($cid)) {
                return $cid;
            }
            $this->safeLog('warning', 'webhook', 'mapping.orphan', [
                'platform_user_id' => $platformUserId,
                'client_id' => $cid
            ]);
            $cid = 0; // wymusi NULL w messages
        }

        // 2) Brak mapowania → utwórz klienta + mapping
        $this->pdo->beginTransaction();
        try {
            // token prosty gdy nie masz generatora
            $tok = 'olaj-' . bin2hex(random_bytes(4));
            $displayName = 'Klient-' . $tok;

            // INSERT klienta
            $this->pdo->prepare("
                INSERT INTO clients (owner_id, token, name, registered_at, last_seen)
                VALUES (:oid, :tok, :name, NOW(), NOW())
            ")->execute([
                ':oid'  => (int)$this->ownerId,
                ':tok'  => $tok,
                ':name' => $displayName,
            ]);
            $newCid = (int)$this->pdo->lastInsertId();
            if ($newCid <= 0) {
                throw new \RuntimeException('client_insert_failed');
            }

            // Mapping do client_platform_ids — dynamicznie wg kolumn
            $meta   = $this->columns('client_platform_ids');
            $cols   = ['client_id', 'owner_id', 'platform', 'platform_user_id', 'created_at'];
            $vals   = [':cid', ':oid', ':pf', ':puid', 'NOW()'];
            $params = [
                ':cid'  => $newCid,
                ':oid'  => (int)$this->ownerId,
                ':pf'   => $platform,
                ':puid' => $platformUserId,
            ];

            if (isset($meta['page_id'])) {
                $val = $pageId;
                if ($meta['page_id']['Null'] === 'NO' && $val === null) {
                    $val = '';
                }
                $cols[] = 'page_id';
                $vals[] = ':pid';
                $params[':pid'] = $val;
            }

            if (isset($meta['thread_id'])) {
                $cols[] = 'thread_id';
                $vals[] = 'NULL';
            }
            if (isset($meta['note'])) {
                $cols[] = 'note';
                $vals[] = 'NULL';
            }
            if (isset($meta['flags'])) {
                $cols[] = 'flags';
                $vals[] = "''";
            }
            if (isset($meta['metadata'])) {
                $cols[] = 'metadata';
                $vals[] = 'NULL';
            }

            $sql = "INSERT INTO client_platform_ids (" . implode(',', $cols) . ")
                    VALUES (" . implode(',', $vals) . ")";
            $this->pdo->prepare($sql)->execute($params);

            $this->pdo->commit();
            return $newCid;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->safeLog('error', 'webhook', 'client.create_fail', [
                '_ex_message' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /** verify_token: facebook_tokens.verify_token → owner_settings('fb.verify_token') */
    private function getVerifyToken(int $ownerId): ?string
    {
        try {
            $st = $this->pdo->prepare("SELECT verify_token FROM facebook_tokens WHERE owner_id=:oid ORDER BY id DESC LIMIT 1");
            $st->execute([':oid' => $ownerId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!empty($row['verify_token'])) return (string)$row['verify_token'];
        } catch (Throwable $__) {
        }

        try {
            $st = $this->pdo->prepare("SELECT value FROM owner_settings WHERE owner_id=:oid AND `key`='fb.verify_token' LIMIT 1");
            $st->execute([':oid' => $ownerId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!empty($row['value'])) return (string)$row['value'];
        } catch (Throwable $__) {
        }

        return null;
    }

    /** Utils */
    private function msToSec(int $maybeMs): int
    {
        // 13 cyfr (ms) → /1000; 10 cyfr (s) → bez zmian
        return ($maybeMs > 2000000000) ? intdiv($maybeMs, 1000) : $maybeMs;
    }

    /** Meta kolumn tabeli */
    private function columns(string $table): array
    {
        try {
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `$table`");
            $stmt->execute();
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[$row['Field']] = ['Null' => $row['Null'] ?? 'YES', 'Default' => $row['Default'] ?? null, 'Type' => $row['Type'] ?? ''];
            }
            return $out;
        } catch (Throwable $__) {
            return [];
        }
    }

    private function clientExists(int $clientId): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM clients WHERE id=:cid LIMIT 1");
        $st->execute([':cid' => $clientId]);
        return (bool)$st->fetchColumn();
    }

    private function normalizeClientId($clientIdMixed): ?int
    {
        if (is_int($clientIdMixed) || ctype_digit((string)$clientIdMixed)) {
            $cid = (int)$clientIdMixed;
            return $cid > 0 ? $cid : null;
        }
        return null;
    }

    /** Pomocnicze: bezpieczne logg() */
    private function safeLog(string $level, string $channel, string $event, array $ctx = []): void
    {
        if (\function_exists('logg')) {
            try {
                logg($level, $channel, $event, $ctx, ['owner_id' => $this->ownerId, 'context' => 'webhook']);
                return;
            } catch (Throwable $__) {
            }
        }
        // fallback do error_log
        error_log("[$level][$channel] $event " . json_encode($ctx, JSON_UNESCAPED_UNICODE));
    }

    /** WHERE dla update DUP (wspiera oba warianty unikatu) */
    private function eventWhere(): string
    {
        // domyślnie unikat to (owner_id, mid); jeśli masz UNIQUE(mid) — też zadziała
        return " ( (owner_id = :oid AND mid = :mid) OR (mid = :mid) ) ";
    }
    private function eventWhereParams(string $mid): array
    {
        return [':oid' => (int)$this->ownerId, ':mid' => $mid];
    }

    /** Static helper: rozpoznanie owner_id (GET/header) */
    public static function resolveOwnerId(PDO $pdo, ?int $fromGet = null, array $headers = []): int
    {
        if ($fromGet && $fromGet > 0) return (int)$fromGet;
        $hdr = $headers['X-Owner-Id'] ?? $headers['x-owner-id'] ?? null;
        if ($hdr && ctype_digit((string)$hdr)) return (int)$hdr;
        return 1;
    }
}
