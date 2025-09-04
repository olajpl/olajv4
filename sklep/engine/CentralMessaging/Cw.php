<?php
// engine/CentralMessaging/Cw.php — Olaj V4
// Natychmiastowa wysyłka DM na Messenger + audyt w `messages`.
// - źródło tokenu: owner_settings (messenger.page_access_token | facebook.page_token), fallback: facebook_tokens.page_token
// - kompatybilność z CwBridge (enqueue/queue/publish/push/dispatch/…)
// - brak HY093 (unikalne placeholdery), mocne logi

declare(strict_types=1);

namespace Engine\CentralMessaging;

use PDO;
use Throwable;

final class Cw
{
    /**
     * Wyślij teraz DM na Messenger i zapisz audyt w `messages`.
     * $textOrMsg:
     *   - string "Cześć!"
     *   - array  ['text'=>'Cześć!', 'event_key'=>'cart.item_added', 'payload'=>[...], 'dedupe_key'=>'...', ...]
     *
     * @return array{ok:bool, message_id?:string, error?:string, http_code?:int, local_msg_id?:int}
     */
    public static function sendMessengerNow(PDO $pdo, int $ownerId, int $clientId, mixed $textOrMsg, array $meta = [], ?string $pageId = null): array
    {
        self::safeLog('debug', 'cw', 'enter:sendMessengerNow', [
            'owner_id'  => $ownerId,
            'client_id' => $clientId,
            'meta_keys' => is_array($meta) ? array_keys($meta) : [],
        ]);

        [$text, $meta] = self::normalizeMessageInput($textOrMsg, $meta);

        self::safeLog('debug', 'cw', 'normalized', [
            'text' => $text,
            'meta' => $meta,
        ]);

        $psid = self::findPsid($pdo, $ownerId, $clientId);
        self::safeLog('debug', 'cw', 'psid_check', [
            'psid' => $psid,
        ]);

        // Audyt insert
        $msgSql = "INSERT INTO messages
               (owner_id, client_id, direction, channel, sender_type,
                platform, platform_user_id, status, content, metadata, created_at)
               VALUES
               (:oid, :cid, 'out', 'messenger', 'system',
                'facebook', :psid, 'queued', :content, :meta, NOW())";
        $msgParams = [
            ':oid'     => $ownerId,
            ':cid'     => $clientId,
            ':psid'    => $psid,
            ':content' => $text,
            ':meta'    => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        try {
            $pdo->prepare($msgSql)->execute($msgParams);
        } catch (Throwable $e) {
            self::safeLog('error', 'cw', 'insert_failed', ['err' => $e->getMessage(), 'stage' => 'queued']);
            return ['ok' => false, 'error' => 'insert_failed'];
        }
        $localMsgId = (int)$pdo->lastInsertId();
        self::safeLog('debug', 'cw', 'inserted', [
            'local_msg_id' => $localMsgId
        ]);

        // Brak PSID
        if (!$psid) {
            self::safeLog('warning', 'cw', 'no_psid', ['owner_id' => $ownerId, 'client_id' => $clientId, 'local_msg_id' => $localMsgId]);
            self::setMessageStatus($pdo, $localMsgId, 'error', ['reason' => 'no_psid']);
            return ['ok' => false, 'error' => 'no_psid', 'local_msg_id' => $localMsgId];
        }

        // Token
        $tok = self::resolveFbCreds($pdo, $ownerId, $pageId);
        self::safeLog('debug', 'cw', 'token_resolved', $tok);

        if (empty($tok['page_token'])) {
            self::safeLog('error', 'cw', 'no_token', ['owner_id' => $ownerId, 'page_id' => $pageId, 'source' => $tok['source'] ?? 'none', 'local_msg_id' => $localMsgId]);
            self::setMessageStatus($pdo, $localMsgId, 'error', ['reason' => 'no_token']);
            return ['ok' => false, 'error' => 'no_token', 'local_msg_id' => $localMsgId];
        }

        // Curl do Graph API
        $payload = [
            'recipient'      => ['id' => $psid],
            'message'        => ['text' => $text],
            'messaging_type' => 'UPDATE',
        ];
        $qs = ['access_token' => $tok['page_token']];
        if (!empty($tok['app_secret'])) {
            $qs['appsecret_proof'] = hash_hmac('sha256', $tok['page_token'], (string)$tok['app_secret']);
        }
        $url = 'https://graph.facebook.com/v19.0/me/messages?' . http_build_query($qs);

        self::safeLog('debug', 'cw', 'curl.prepare', [
            'url'     => $url,
            'payload' => $payload,
        ]);

        [$httpCode, $respBody] = self::curlJsonPost($url, $payload);
        $resp = json_decode($respBody, true) ?: [];

        self::safeLog('debug', 'cw', 'curl.response', [
            'http' => $httpCode,
            'body' => $respBody,
            'parsed' => $resp,
        ]);

        if ($httpCode === 200 && !empty($resp['message_id'])) {
            $messageId = (string)$resp['message_id'];
            self::setMessageStatus($pdo, $localMsgId, 'sent', [
                'platform_msg_id' => $messageId,
                'resp'            => ['http' => $httpCode],
                'source'          => $tok['source'] ?? null,
            ]);
            self::safeLog('info', 'cw', 'sent', [
                'local_msg_id'    => $localMsgId,
                'platform_msg_id' => $messageId,
                'source'          => $tok['source'] ?? null,
            ]);

            try {
                $pdo->prepare("UPDATE messages SET platform_msg_id=:pmid WHERE id=:id LIMIT 1")
                    ->execute([':pmid' => $messageId, ':id' => $localMsgId]);
            } catch (Throwable $__) {
            }

            return ['ok' => true, 'message_id' => $messageId, 'local_msg_id' => $localMsgId];
        }

        // Błąd wysyłki
        self::setMessageStatus($pdo, $localMsgId, 'error', [
            'http_code' => $httpCode,
            'resp'      => $resp,
            'source'    => $tok['source'] ?? null
        ]);
        self::safeLog('error', 'cw', 'send_error', ['http' => $httpCode, 'resp' => $resp, 'local_msg_id' => $localMsgId, 'source' => $tok['source'] ?? null]);

        return ['ok' => false, 'error' => 'send_error', 'http_code' => $httpCode, 'local_msg_id' => $localMsgId];
    }

    /**
     * Wysyła wiadomość z przyciskami (Messenger structured message).
     *
     * @param PDO    $pdo
     * @param int    $ownerId
     * @param int    $clientId
     * @param array  $structuredMsg — ['text'=>'...', 'buttons'=>[...]]
     * @param array  $meta
     * @return array{ok:bool, message_id?:string, error?:string, http_code?:int}
     */
    public static function sendStructuredMessage(PDO $pdo, int $ownerId, int $clientId, array $structuredMsg, array $meta = [], ?string $pageId = null): array
    {
        self::safeLog('debug', 'cw', 'enter:sendStructuredMessage', [
            'owner_id'  => $ownerId,
            'client_id' => $clientId,
            'keys'      => array_keys($structuredMsg),
        ]);

        $text    = trim((string)($structuredMsg['text'] ?? ''));
        $buttons = array_values((array)($structuredMsg['buttons'] ?? []));

        // twarde limity Messengera
        if ($text === '' || !$buttons) {
            return ['ok' => false, 'error' => 'empty_structured_message'];
        }
        if (count($buttons) > 3) {
            $buttons = array_slice($buttons, 0, 3);
        }

        // znajdź PSID
        $psid = self::findPsid($pdo, $ownerId, $clientId);

        // audyt: INSERT do messages (schema: content+metadata)
        $msgSql = "INSERT INTO messages
               (owner_id, client_id, direction, channel, sender_type,
                platform, platform_user_id, status, content, metadata, created_at)
               VALUES
               (:oid, :cid, 'out', 'messenger', 'system',
                'facebook', :psid, 'queued', :content, :meta, NOW())";

        $metaForDb = $meta;
        $metaForDb['structured'] = true;
        $metaForDb['buttons']    = $buttons;

        $params = [
            ':oid'     => $ownerId,
            ':cid'     => $clientId,
            ':psid'    => $psid,
            ':content' => $text,
            ':meta'    => json_encode($metaForDb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        try {
            $pdo->prepare($msgSql)->execute($params);
        } catch (\Throwable $e) {
            self::safeLog('error', 'cw', 'insert_failed_struct', ['err' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'insert_failed'];
        }
        $localMsgId = (int)$pdo->lastInsertId();

        if (!$psid) {
            self::safeLog('warning', 'cw', 'no_psid_struct', ['local_msg_id' => $localMsgId]);
            self::setMessageStatus($pdo, $localMsgId, 'error', ['reason' => 'no_psid']);
            return ['ok' => false, 'error' => 'no_psid', 'local_msg_id' => $localMsgId];
        }

        // token
        $tok = self::resolveFbCreds($pdo, $ownerId, $pageId);
        if (empty($tok['page_token'])) {
            self::safeLog('error', 'cw', 'no_token_struct', ['local_msg_id' => $localMsgId]);
            self::setMessageStatus($pdo, $localMsgId, 'error', ['reason' => 'no_token']);
            return ['ok' => false, 'error' => 'no_token', 'local_msg_id' => $localMsgId];
        }

        // Normalizacja guzików do formatu FB
        $fbButtons = [];
        foreach ($buttons as $b) {
            $type  = strtolower((string)($b['type'] ?? ''));
            $title = (string)($b['title'] ?? '');
            if ($title === '') continue;

            if ($type === 'web_url') {
                $url = (string)($b['url'] ?? '');
                if ($url !== '') {
                    $fbButtons[] = [
                        'type'  => 'web_url',
                        'title' => $title,
                        'url'   => $url,
                    ];
                }
            } elseif ($type === 'postback') {
                $payload = (string)($b['payload'] ?? '');
                if ($payload !== '') {
                    $fbButtons[] = [
                        'type'    => 'postback',
                        'title'   => $title,
                        'payload' => $payload,
                    ];
                }
            }
            if (count($fbButtons) >= 3) break;
        }

        if (!$fbButtons) {
            // brak poprawnych przycisków → wyślij jako plain text
            return self::sendMessengerNow($pdo, $ownerId, $clientId, $text, $meta, $pageId);
        }

        // Messenger Button Template
        $payload = [
            'recipient' => ['id' => $psid],
            'message'   => [
                'attachment' => [
                    'type'    => 'template',
                    'payload' => [
                        'template_type' => 'button',
                        'text'          => $text,
                        'buttons'       => $fbButtons,
                    ],
                ],
            ],
            'messaging_type' => 'UPDATE',
        ];

        $qs = ['access_token' => $tok['page_token']];
        if (!empty($tok['app_secret'])) {
            $qs['appsecret_proof'] = hash_hmac('sha256', $tok['page_token'], (string)$tok['app_secret']);
        }
        $url = 'https://graph.facebook.com/v19.0/me/messages?' . http_build_query($qs);

        [$httpCode, $respBody] = self::curlJsonPost($url, $payload);
        $resp = json_decode($respBody, true) ?: [];

        if ($httpCode === 200 && !empty($resp['message_id'])) {
            $messageId = (string)$resp['message_id'];
            self::setMessageStatus($pdo, $localMsgId, 'sent', [
                'platform_msg_id' => $messageId,
                'resp'            => ['http' => $httpCode],
                'source'          => $tok['source'] ?? null,
                'structured'      => true,
            ]);
            try {
                $pdo->prepare("UPDATE messages SET platform_msg_id=:pmid WHERE id=:id LIMIT 1")
                    ->execute([':pmid' => $messageId, ':id' => $localMsgId]);
            } catch (\Throwable $__) {
            }
            self::safeLog('info', 'cw', 'sent_struct', [
                'local_msg_id'    => $localMsgId,
                'platform_msg_id' => $messageId,
            ]);
            return ['ok' => true, 'message_id' => $messageId, 'local_msg_id' => $localMsgId];
        }

        self::setMessageStatus($pdo, $localMsgId, 'error', [
            'http_code'  => $httpCode,
            'resp'       => $resp,
            'structured' => true,
        ]);
        self::safeLog('error', 'cw', 'send_error_struct', [
            'http' => $httpCode,
            'resp' => $resp,
            'local_msg_id' => $localMsgId,
        ]);
        return ['ok' => false, 'error' => 'send_error', 'http_code' => $httpCode, 'local_msg_id' => $localMsgId];
    }


    /**
     * Dodaj wiadomość do audytu (status='queued'), bez wołania Graph API.
     * $textOrMsg: patrz sendMessengerNow().
     * @return int local message id
     */
    public static function enqueueMessenger(PDO $pdo, int $ownerId, int $clientId, mixed $textOrMsg, array $meta = []): int
    {
        [$text, $meta] = self::normalizeMessageInput($textOrMsg, $meta);

        $psid = self::findPsid($pdo, $ownerId, $clientId);
        $sql  = "INSERT INTO messages
                 (owner_id, client_id, direction, channel, sender_type,
                  platform, platform_user_id, status, content, metadata, created_at)
                 VALUES
                 (:oid, :cid, 'out', 'messenger', 'system',
                  'facebook', :psid, 'queued', :content, :meta, NOW())";
        $params = [
            ':oid'     => $ownerId,
            ':cid'     => $clientId,
            ':psid'    => $psid,
            ':content' => $text,
            ':meta'    => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        try {
            $pdo->prepare($sql)->execute($params);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            self::safeLog('error', 'cw', 'enqueue_failed', ['err' => $e->getMessage()]);
            return 0;
        }
    }

    // ───────────── KOMPATYBILNOŚĆ Z CwBridge (różne nazwy metod) ─────────────

    /** Enqueue przez ujednolicony interfejs (wymagany przez CwBridge). */
    public static function enqueue(PDO $pdo, int $ownerId, array|string $msg): array
    {
        [$text, $meta] = self::normalizeMessageInput($msg, []);
        $clientId = 0;
        if (is_array($msg)) {
            $clientId = (int)($msg['client_id'] ?? ($msg['payload']['client_id'] ?? 0));
        }
        $id = self::enqueueMessenger($pdo, $ownerId, $clientId, $text, $meta);
        return ['ok' => $id > 0, 'local_msg_id' => $id, 'note' => $clientId ? null : 'no_client_id'];
    }

    public static function queue(PDO $pdo, int $ownerId, array|string $msg)
    {
        return self::enqueue($pdo, $ownerId, $msg);
    }
    public static function publish(PDO $pdo, int $ownerId, array|string $msg)
    {
        return self::enqueue($pdo, $ownerId, $msg);
    }
    public static function enqueueMessage(PDO $pdo, int $ownerId, array|string $msg)
    {
        return self::enqueue($pdo, $ownerId, $msg);
    }
    public static function push(PDO $pdo, int $ownerId, array|string $msg)
    {
        return self::enqueue($pdo, $ownerId, $msg);
    }

    public static function dispatch(PDO $pdo, int $ownerId, array|string $msg): array
    {
        $channel = 'messenger';
        if (is_array($msg)) $channel = (string)($msg['channel'] ?? 'messenger');
        return self::dispatchByChannel($pdo, $ownerId, $channel, $msg);
    }

    public static function dispatchByChannel(PDO $pdo, int $ownerId, string $channel, array|string $msg): array
    {
        if ($channel === 'messenger') {
            $clientId = 0;
            if (is_array($msg)) $clientId = (int)($msg['client_id'] ?? ($msg['payload']['client_id'] ?? 0));
            if ($clientId > 0) return self::sendMessengerNow($pdo, $ownerId, $clientId, $msg);
            return self::enqueue($pdo, $ownerId, $msg);
        }
        // TODO: email/sms/push – na razie audyt
        return self::enqueue($pdo, $ownerId, $msg);
    }

    // ───────────────────────── helpers / utils ─────────────────────────

    /**
     * Normalizuje wejście wiadomości:
     *  - jeśli string → zwraca [$text, $meta]
     *  - jeśli array  → wyjmuje 'text' do $text, reszta ląduje w $meta (zmergowana)
     * Gwarantuje, że $text jest stringiem, a $meta serializowalne do JSON.
     *
     * @return array{0:string, 1:array}
     */
    private static function normalizeMessageInput(mixed $textOrMsg, array $meta): array
    {
        if (is_array($textOrMsg)) {
            $text = (string)($textOrMsg['text'] ?? '');
            $merged = $textOrMsg;
            unset($merged['text']);
            $meta = array_replace($merged, $meta); // meta z argumentu ma pierwszeństwo
        } else {
            $text = (string)$textOrMsg;
        }
        return [$text, $meta];
    }

    private static function findPsid(PDO $pdo, int $ownerId, int $clientId): ?string
    {
        try {
            $st = $pdo->prepare("
                SELECT platform_user_id
                FROM client_platform_ids
                WHERE owner_id=:oid AND client_id=:cid
                  AND platform IN ('messenger','facebook')
                ORDER BY id DESC LIMIT 1
            ");
            $st->execute([':oid' => $ownerId, ':cid' => $clientId]);
            $val = $st->fetchColumn();
            return $val ? (string)$val : null;
        } catch (Throwable $__) {
            return null;
        }
    }

    /**
     * Resolver tokenów: owner_settings → fallback facebook_tokens
     * owner_settings: messenger.page_access_token | facebook.page_token, facebook.app_secret, facebook.page_id
     * facebook_tokens: page_token, app_secret
     *
     * @return array{page_id:?string, page_token:?string, app_secret:?string, source:string}
     */
    private static function resolveFbCreds(PDO $pdo, int $ownerId, ?string $forcePageId = null): array
    {
        // 1) owner_settings (źródło prawdy)
        try {
            $st = $pdo->prepare("
                SELECT `key`, `value`
                FROM owner_settings
                WHERE owner_id = :oid
                  AND `key` IN (
                    'messenger.page_access_token',
                    'facebook.page_token',
                    'facebook.app_secret',
                    'facebook.page_id'
                  )
            ");
            $st->execute([':oid' => $ownerId]);
            $kv = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $kv[$r['key']] = $r['value'];
            }

            $pageToken = $kv['messenger.page_access_token'] ?? $kv['facebook.page_token'] ?? null;
            $appSecret = $kv['facebook.app_secret'] ?? null;
            $pageIdOs  = $kv['facebook.page_id'] ?? null;
            $pid       = $forcePageId ?: $pageIdOs;

            if ($pageToken && $pid) {
                return [
                    'page_id'    => $pid,
                    'page_token' => $pageToken,
                    'app_secret' => $appSecret,
                    'source'     => 'owner_settings',
                ];
            }
        } catch (Throwable $__) {
            // cicho – fallback poniżej
        }

        // 2) fallback: facebook_tokens (multi-page / legacy)
        try {
            if ($forcePageId) {
                $st2 = $pdo->prepare("
                    SELECT page_id, page_token, app_secret
                    FROM facebook_tokens
                    WHERE owner_id=:oid AND page_id=:pid
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $st2->execute([':oid' => $ownerId, ':pid' => $forcePageId]);
            } else {
                $st2 = $pdo->prepare("
                    SELECT page_id, page_token, app_secret
                    FROM facebook_tokens
                    WHERE owner_id=:oid
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $st2->execute([':oid' => $ownerId]);
            }
            $row = $st2->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['page_token'])) {
                return [
                    'page_id'    => (string)($row['page_id'] ?? $forcePageId),
                    'page_token' => (string)$row['page_token'],
                    'app_secret' => $row['app_secret'] ?? null,
                    'source'     => 'facebook_tokens',
                ];
            }
        } catch (Throwable $__) {
        }

        return ['page_id' => null, 'page_token' => null, 'app_secret' => null, 'source' => 'none'];
    }

    private static function setMessageStatus(PDO $pdo, int $id, string $status, array $extra = []): void
    {
        try {
            $meta = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $pdo->prepare("UPDATE messages SET status=:st, metadata=:meta WHERE id=:id LIMIT 1")
                ->execute([':st' => $status, ':meta' => $meta, ':id' => $id]);
        } catch (Throwable $__) {
        }
    }

    private static function curlJsonPost(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $err = curl_error($ch);
            $body = json_encode(['error' => 'curl_error', 'message' => $err], JSON_UNESCAPED_UNICODE);
        }
        curl_close($ch);
        return [$http, (string)$body];
    }

    private static function safeLog(string $level, string $channel, string $event, array $ctx = []): void
    {
        if (\function_exists('logg')) {
            try {
                logg($level, $channel, $event, $ctx, ['context' => 'cw']);
                return;
            } catch (Throwable $__) {
            }
        }
        error_log("[$level][$channel] $event " . json_encode($ctx, JSON_UNESCAPED_UNICODE));
    }
}
