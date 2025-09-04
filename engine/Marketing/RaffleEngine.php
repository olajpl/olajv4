<?php
// engine/marketing/RaffleEngine.php — Olaj.pl V4
// RaffleEngine: tworzenie losowań, wpisy, ban, freeze (commit), draw (reveal), nagrody, fulfillment, reset, search
declare(strict_types=1);

namespace Engine\Marketing;

use PDO;
use Throwable;

if (!\function_exists('logg')) {
    // miękki fallback logera (gdy includes/log.php nie zostało dołączone)
    function logg(string $level, string $channel, string $message, array $context = [], array $extra = []): void {
        error_log('[raffles][' . $level . '][' . $channel . '] ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE));
    }
}

final class RaffleEngine
{
    private PDO $pdo;
    private int $ownerId;

    public function __construct(PDO $pdo, int $ownerId)
    {
        $this->pdo = $pdo;
        $this->ownerId = $ownerId;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /* ─────────────────────────────────────────
     * DRAW: CRUD / lifecycle
     * ───────────────────────────────────────── */

    /**
     * Utwórz losowanie (status: draft).
     * $data: title, description?, live_stream_id?, allow_duplicates?, cooldown_days?, keyword?, created_by_admin_id?
     */
    public function createDraw(array $data): int
    {
        $st = $this->pdo->prepare("
            INSERT INTO draws
                (owner_id, live_stream_id, title, description, status, participants_count,
                 allow_duplicates, cooldown_days, keyword, created_by_admin_id, created_at, updated_at)
            VALUES
                (:oid, :lid, :title, :descr, 'draft', 0,
                 :allow_dup, :cooldown, :keyword, :admin_id, NOW(), NOW())
        ");
        $st->execute([
            ':oid'       => $this->ownerId,
            ':lid'       => (int)($data['live_stream_id'] ?? 0) ?: null,
            ':title'     => (string)($data['title'] ?? 'Losowanie'),
            ':descr'     => $data['description'] ?? null,
            ':allow_dup' => (int)($data['allow_duplicates'] ?? 0),
            ':cooldown'  => (int)($data['cooldown_days'] ?? 7),
            ':keyword'   => $data['keyword'] ?? null,
            ':admin_id'  => (int)($data['created_by_admin_id'] ?? 0) ?: null,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        logg('info', 'raffles', 'createDraw', ['draw_id' => $id, 'owner_id' => $this->ownerId]);
        return $id;
    }

    /** Zmień status na 'arming' (zbieramy wpisy) */
    public function openForEntries(int $drawId): bool
    {
        $st = $this->pdo->prepare("UPDATE draws SET status='arming', updated_at=NOW() WHERE id=:id AND owner_id=:oid");
        $ok = $st->execute([':id' => $drawId, ':oid' => $this->ownerId]);
        logg('info', 'raffles', 'openForEntries', ['draw_id' => $drawId, 'ok' => $ok]);
        return (bool)$ok;
    }

    /**
     * Dodaj uczestnika (entry). Respektuje allow_duplicates i ban-listę.
     * $entry: platform('client'|'messenger'|'manual'|...), platform_id?, display_name?, weight?, source?, added_by_admin_id?
     */
    public function addEntry(int $drawId, array $entry): array
    {
        $this->pdo->beginTransaction();
        try {
            // Lock draw
            $draw = $this->fetchDrawForUpdate($drawId);
            if (!$draw) throw new \Exception('Losowanie nie istnieje.');
            if ($draw['status'] !== 'arming') throw new \Exception('Lista zamknięta (nie jest w statusie "arming").');

            $platform   = trim((string)($entry['platform'] ?? 'manual'));
            $platformId = trim((string)($entry['platform_id'] ?? ''));
            $display    = trim((string)($entry['display_name'] ?? ''));

            // Autouzupełnienie display dla client
            if ($platform === 'client' && $platformId !== '' && $display === '') {
                $q = $this->pdo->prepare("SELECT COALESCE(NULLIF(TRIM(name),''), CONCAT('Klient #',id)) AS name
                                          FROM clients
                                          WHERE owner_id=:oid AND id=:cid LIMIT 1");
                $q->execute([':oid' => $this->ownerId, ':cid' => (int)$platformId]);
                if ($r = $q->fetch(PDO::FETCH_ASSOC)) $display = (string)$r['name'];
            }
            if ($display === '') throw new \Exception('Brak display_name');

            // Ban-check
            if ($platformId !== '') {
                $ban = $this->pdo->prepare("SELECT 1 FROM draw_bans WHERE owner_id=:oid AND platform=:pf AND platform_id=:pid LIMIT 1");
                $ban->execute([':oid'=>$this->ownerId, ':pf'=>$platform, ':pid'=>$platformId]);
                if ($ban->fetch()) throw new \Exception('Użytkownik na ban-liście.');
            }

            // Duplikaty
            $allowDup = (int)$draw['allow_duplicates'] === 1;
            if (!$allowDup) {
                if ($platformId !== '') {
                    $du = $this->pdo->prepare("SELECT 1 FROM draw_entries WHERE draw_id=:did AND platform=:pf AND platform_id=:pid LIMIT 1");
                    $du->execute([':did'=>$drawId, ':pf'=>$platform, ':pid'=>$platformId]);
                    if ($du->fetch()) {
                        $this->pdo->commit();
                        return ['ok'=>true, 'duplicate'=>true];
                    }
                } else {
                    $du = $this->pdo->prepare("SELECT 1 FROM draw_entries WHERE draw_id=:did AND platform=:pf AND display_name=:nm LIMIT 1");
                    $du->execute([':did'=>$drawId, ':pf'=>$platform, ':nm'=>$display]);
                    if ($du->fetch()) {
                        $this->pdo->commit();
                        return ['ok'=>true, 'duplicate'=>true];
                    }
                }
            }

            $weight = (int)($entry['weight'] ?? 1);
            if ($weight < 1) $weight = 1;

            $source = (string)($entry['source'] ?? (($platform === 'client') ? 'manual-client' : 'manual'));
            $addedBy = (int)($entry['added_by_admin_id'] ?? 0) ?: null;

            $ins = $this->pdo->prepare("
                INSERT IGNORE INTO draw_entries
                    (draw_id, platform, platform_id, display_name, weight, source, added_by_admin_id, created_at)
                VALUES
                    (:did, :pf, :pid, :name, :w, :src, :aid, NOW())
            ");
            $ins->bindValue(':did', $drawId, PDO::PARAM_INT);
            $ins->bindValue(':pf',  $platform, PDO::PARAM_STR);
            ($platformId === '')
                ? $ins->bindValue(':pid', null, PDO::PARAM_NULL)
                : $ins->bindValue(':pid', $platformId, PDO::PARAM_STR);
            $ins->bindValue(':name', $display, PDO::PARAM_STR);
            $ins->bindValue(':w',    $weight, PDO::PARAM_INT);
            $ins->bindValue(':src',  $source, PDO::PARAM_STR);
            $ins->bindValue(':aid',  $addedBy, $addedBy===null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $ins->execute();

            $inserted = $ins->rowCount() > 0;

            if ($inserted) {
                $this->pdo->prepare("UPDATE draws SET participants_count = participants_count + 1, updated_at=NOW() WHERE id=:id")
                          ->execute([':id' => $drawId]);
            }

            $this->pdo->commit();
            logg('info', 'raffles', 'addEntry', ['draw_id'=>$drawId, 'inserted'=>$inserted]);
            return ['ok'=>true, 'inserted'=>$inserted];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            logg('error', 'raffles', 'addEntry_fail', ['draw_id'=>$drawId, 'err'=>$e->getMessage()]);
            return ['ok'=>false, 'error'=>$e->getMessage()];
        }
    }

    /**
     * Ban uczestnika globalnie (owner, platform, platform_id). Usuwa wpisy z danego losowania i koryguje licznik.
     */
    public function banEntry(int $drawId, string $platform, string $platformId, ?string $reason = null, ?int $adminId = null): bool
    {
        $this->pdo->beginTransaction();
        try {
            $draw = $this->fetchDrawForUpdate($drawId);
            if (!$draw) throw new \Exception('Losowanie nie istnieje.');
            if ($draw['status'] !== 'arming') throw new \Exception('Lista zamknięta.');

            // Insert IGNORE do ban-listy
            $ban = $this->pdo->prepare("
                INSERT IGNORE INTO draw_bans (owner_id, platform, platform_id, reason, created_by_admin_id, created_at)
                VALUES (:oid, :pf, :pid, :reason, :aid, NOW())
            ");
            $ban->execute([
                ':oid' => $this->ownerId, ':pf' => $platform, ':pid' => $platformId,
                ':reason' => $reason, ':aid' => $adminId
            ]);

            // Usuń jego wpisy z tego losowania
            $del = $this->pdo->prepare("DELETE FROM draw_entries WHERE draw_id=:did AND platform=:pf AND platform_id=:pid");
            $del->execute([':did'=>$drawId, ':pf'=>$platform, ':pid'=>$platformId]);
            $removed = $del->rowCount();

            if ($removed > 0) {
                $this->pdo->prepare("UPDATE draws SET participants_count = GREATEST(0, participants_count - :n), updated_at=NOW() WHERE id=:id")
                          ->execute([':n'=>$removed, ':id'=>$drawId]);
            }

            $this->pdo->commit();
            logg('info', 'raffles', 'banEntry', ['draw_id'=>$drawId, 'platform'=>$platform, 'removed'=>$removed]);
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            logg('error', 'raffles', 'banEntry_fail', ['draw_id'=>$drawId, 'err'=>$e->getMessage()]);
            return false;
        }
    }

    /**
     * Freeze (commit) — zamraża listę wpisów, zapisuje commit_hash + reveal_salt, status 'frozen'.
     */
    public function freezeDraw(int $drawId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $draw = $this->fetchDrawForUpdate($drawId);
            if (!$draw) throw new \Exception('Losowanie nie istnieje.');
            if ($draw['status'] !== 'arming') throw new \Exception('Losowanie nie jest w statusie "arming".');

            $entries = $this->fetchEntries($drawId);
            if (empty($entries)) throw new \Exception('Brak uczestników.');

            $json = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $commitHash = hash('sha256', $json);
            $salt = random_bytes(32);

            $st = $this->pdo->prepare("
                UPDATE draws
                SET status='frozen', commit_hash=:ch, reveal_salt=:salt, updated_at=NOW()
                WHERE id=:id
            ");
            $st->bindValue(':ch', $commitHash, PDO::PARAM_STR);
            $st->bindValue(':salt', $salt, PDO::PARAM_LOB);
            $st->bindValue(':id', $drawId, PDO::PARAM_INT);
            $st->execute();

            $this->pdo->commit();
            logg('info', 'raffles', 'freezeDraw', ['draw_id'=>$drawId]);
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            logg('error', 'raffles', 'freezeDraw_fail', ['draw_id'=>$drawId, 'err'=>$e->getMessage()]);
            return false;
        }
    }

    /**
     * Losowanie zwycięzcy (deterministyczne, ważone). Status 'frozen' → 'drawn'.
     * Zwraca: ['winner'=>entry, 'seed'=>$seedHex, 'index'=>$idx, 'result_id'=>... , 'claim_id'=>...]
     */
    public function drawWinner(int $drawId): array
    {
        $this->pdo->beginTransaction();
        try {
            $draw = $this->fetchDrawForUpdate($drawId);
            if (!$draw) throw new \Exception('Losowanie nie istnieje.');
            if ($draw['status'] !== 'frozen') throw new \Exception('Najpierw zamroź listę (freeze).');

            $entries = $this->fetchEntries($drawId, true); // włącznie z weight
            if (empty($entries)) throw new \Exception('Brak uczestników.');

            $json = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $salt = (string)$draw['reveal_salt']; // VARBINARY → PHP stream; rzutujemy na string
            $seedHex = hash('sha256', $json . $salt);

            // Deterministyczny "random": generujemy źródło u64 dla wyboru
            $u64 = $this->u64FromSeed($seedHex, 0);

            // Wybór ważony (sumujemy wagi, wybieramy próg mod sumWeights)
            $sumW = 0;
            foreach ($entries as $e) $sumW += max(1, (int)$e['weight']);
            if ($sumW <= 0) $sumW = count($entries);

            $target = $u64 % $sumW;
            $idx = $this->findWeightedIndex($entries, $target);

            // Cooldown zwycięzców (jeśli platform_id niepuste)
            $candidateIndex = $idx;
            $maxAttempts = min(64, max(4, count($entries))); // górny limit, żeby nie kręcić wiecznie
            $attempt = 0;
            while ($attempt < $maxAttempts) {
                $w = $entries[$candidateIndex];
                if (!$this->isOnCooldown((int)$draw['cooldown_days'], (string)$w['platform'], (string)($w['platform_id'] ?? ''))) {
                    $idx = $candidateIndex; // znaleziony zwycięzca
                    break;
                }
                $attempt++;
                $u64 = $this->u64FromSeed($seedHex, $attempt); // kolejna deterministyczna próba
                $target = $u64 % $sumW;
                $candidateIndex = $this->findWeightedIndex($entries, $target);
            }
            $winner = $entries[$idx];

            // Zapis wyniku
            $insR = $this->pdo->prepare("
                INSERT INTO draw_results
                    (draw_id, owner_id, live_stream_id, platform, platform_id, display_name, winner_index, won_at, created_at)
                VALUES
                    (:did, :oid, :lid, :pf, :pid, :name, :idx, NOW(), NOW())
            ");
            $insR->execute([
                ':did'  => $drawId,
                ':oid'  => $this->ownerId,
                ':lid'  => $draw['live_stream_id'],
                ':pf'   => $winner['platform'],
                ':pid'  => $winner['platform_id'],
                ':name' => $winner['display_name'],
                ':idx'  => $idx,
            ]);
            $resultId = (int)$this->pdo->lastInsertId();

            // Claim pending
            $insC = $this->pdo->prepare("
                INSERT INTO draw_claims
                    (result_id, draw_id, claim_status, claim_token, fulfillment_channel, claimant_platform, claimant_platform_id, created_at, updated_at)
                VALUES
                    (:rid, :did, 'pending', REPLACE(UUID(),'-',''), 'none', :pf, :pid, NOW(), NOW())
            ");
            $insC->execute([
                ':rid' => $resultId, ':did' => $drawId,
                ':pf'  => $winner['platform'], ':pid' => $winner['platform_id']
            ]);
            $claimId = (int)$this->pdo->lastInsertId();

            // Update draws: seed + status
            $upd = $this->pdo->prepare("UPDATE draws SET status='drawn', seed_hex=:seed, drawn_at=NOW(), updated_at=NOW() WHERE id=:id");
            $upd->execute([':seed'=>$seedHex, ':id'=>$drawId]);

            $this->pdo->commit();
            logg('info', 'raffles', 'drawWinner', ['draw_id'=>$drawId, 'result_id'=>$resultId, 'claim_id'=>$claimId]);
            return [
                'ok' => true,
                'winner' => $winner,
                'seed' => $seedHex,
                'index' => $idx,
                'result_id' => $resultId,
                'claim_id' => $claimId,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            logg('error', 'raffles', 'drawWinner_fail', ['draw_id'=>$drawId, 'err'=>$e->getMessage()]);
            return ['ok'=>false, 'error'=>$e->getMessage()];
        }
    }

    /**
     * Przypisz nagrodę do roszczenia; kontrola draw_prizes.quantity vs. reserved.
     */
    public function assignPrize(int $claimId, int $prizeId): bool
    {
        $this->pdo->beginTransaction();
        try {
            // Pobierz claim + prize (lock)
            $c = $this->pdo->prepare("SELECT * FROM draw_claims WHERE id=:id FOR UPDATE");
            $c->execute([':id'=>$claimId]);
            $claim = $c->fetch(PDO::FETCH_ASSOC);
            if (!$claim) throw new \Exception('Brak claimu.');
            if (!in_array($claim['claim_status'], ['pending','verified','contacted'], true)) {
                throw new \Exception('Claim w statusie: ' . $claim['claim_status']);
            }

            $p = $this->pdo->prepare("SELECT * FROM draw_prizes WHERE id=:id FOR UPDATE");
            $p->execute([':id'=>$prizeId]);
            $prize = $p->fetch(PDO::FETCH_ASSOC);
            if (!$prize) throw new \Exception('Brak nagrody.');

            $reserved = (int)$prize['reserved'];
            $quantity = (int)$prize['quantity'];
            if ($reserved >= $quantity) throw new \Exception('Brak dostępnej puli nagród.');

            // Zwiększ reserved i przypnij do claimu
            $this->pdo->prepare("UPDATE draw_prizes SET reserved = reserved + 1, updated_at=NOW() WHERE id=:id")
                      ->execute([':id'=>$prizeId]);
            $this->pdo->prepare("UPDATE draw_claims SET prize_id=:pid, updated_at=NOW() WHERE id=:cid")
                      ->execute([':pid'=>$prizeId, ':cid'=>$claimId]);

            $this->pdo->commit();
            logg('info', 'raffles', 'assignPrize', ['claim_id'=>$claimId, 'prize_id'=>$prizeId]);
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            logg('error', 'raffles', 'assignPrize_fail', ['claim_id'=>$claimId, 'err'=>$e->getMessage()]);
            return false;
        }
    }

    /**
     * Fulfillment roszczenia (wydanie nagrody).
     * $data: notes?, fulfillment_channel? ('none'|'messenger'|'sms'|'email'|'manual')
     */
    public function fulfillClaim(int $claimId, array $data): bool
    {
        $channel = (string)($data['fulfillment_channel'] ?? 'manual');
        $notes   = $data['notes'] ?? null;

        $st = $this->pdo->prepare("
            UPDATE draw_claims
            SET claim_status='fulfilled',
                fulfillment_channel=:ch,
                notes=:n,
                fulfilled_at=NOW(),
                updated_at=NOW()
            WHERE id=:id
        ");
        $ok = $st->execute([':ch'=>$channel, ':n'=>$notes, ':id'=>$claimId]);
        logg('info', 'raffles', 'fulfillClaim', ['claim_id'=>$claimId, 'channel'=>$channel, 'ok'=>$ok]);
        return (bool)$ok;
    }

    /**
     * Reset losowania (czyści entries + licznik; status 'arming'; czyści commit/reveal).
     * Uwaga: nie czyści wyników/claimów ani nagród — to świadoma decyzja (audyt).
     */
    public function resetDraw(int $drawId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM draw_entries WHERE draw_id=:id")->execute([':id'=>$drawId]);
            $this->pdo->prepare("
                UPDATE draws
                SET participants_count=0,
                    status='arming',
                    commit_hash=NULL,
                    reveal_salt=NULL,
                    seed_hex=NULL,
                    drawn_at=NULL,
                    updated_at=NOW()
                WHERE id=:id AND owner_id=:oid
            ")->execute([':id'=>$drawId, ':oid'=>$this->ownerId]);

            $this->pdo->commit();
            logg('info', 'raffles', 'resetDraw', ['draw_id'=>$drawId]);
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            logg('error', 'raffles', 'resetDraw_fail', ['draw_id'=>$drawId, 'err'=>$e->getMessage()]);
            return false;
        }
    }

    /* ─────────────────────────────────────────
     * SEARCH
     * ───────────────────────────────────────── */

    /** Szybkie wyszukiwanie klientów (do 10) po name/token/email/phone */
    public function searchClients(int $ownerId, string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') return [];
        $like = '%' . $q . '%';
        $st = $this->pdo->prepare("
            SELECT id, name, token, email, phone
            FROM clients
            WHERE owner_id=:oid
              AND (name LIKE :q OR token LIKE :q OR email LIKE :q OR phone LIKE :q)
            ORDER BY id DESC
            LIMIT :lim
        ");
        $st->bindValue(':oid', $ownerId, PDO::PARAM_INT);
        $st->bindValue(':q', $like, PDO::PARAM_STR);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /* ─────────────────────────────────────────
     * PRIVATE HELPERS
     * ───────────────────────────────────────── */

    /** Pobierz losowanie (LOCK FOR UPDATE) */
    private function fetchDrawForUpdate(int $drawId): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM draws WHERE id=:id AND owner_id=:oid FOR UPDATE");
        $st->execute([':id'=>$drawId, ':oid'=>$this->ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Pobierz wpisy (flaga $withWeight aby dociągnąć kolumnę weight) */
    private function fetchEntries(int $drawId, bool $withWeight = false): array
    {
        if ($withWeight) {
            $q = $this->pdo->prepare("SELECT platform, platform_id, display_name, weight FROM draw_entries WHERE draw_id=:id ORDER BY id ASC");
        } else {
            $q = $this->pdo->prepare("SELECT platform, platform_id, display_name FROM draw_entries WHERE draw_id=:id ORDER BY id ASC");
        }
        $q->execute([':id'=>$drawId]);
        return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Sprawdź cooldown zwycięzców dla (platform, platform_id) */
    private function isOnCooldown(int $days, string $platform, string $platformId): bool
    {
        if ($days <= 0 || $platformId === '') return false;
        $q = $this->pdo->prepare("
            SELECT 1 FROM draw_results
            WHERE owner_id=:oid AND platform=:pf AND platform_id=:pid
              AND won_at >= (NOW() - INTERVAL :d DAY)
            LIMIT 1
        ");
        $q->bindValue(':oid', $this->ownerId, PDO::PARAM_INT);
        $q->bindValue(':pf',  $platform, PDO::PARAM_STR);
        $q->bindValue(':pid', $platformId, PDO::PARAM_STR);
        $q->bindValue(':d',   $days, PDO::PARAM_INT);
        $q->execute();
        return (bool)$q->fetch();
    }

    /** Z seedHex + offset → u64 (deterministyczny) */
    private function u64FromSeed(string $seedHex, int $offset): int
    {
        // generujemy kolejną porcję: sha256(seedHex . ":" . offset), bierzemy pierwsze 16 hex = 64 bity
        $h = hash('sha256', $seedHex . ':' . $offset);
        $hi = substr($h, 0, 16);
        // konwersja hex → int (bez int overflow: pakujemy do bin i unpack)
        $bin = hex2bin($hi);
        $arr = unpack('J', $bin); // J = unsigned 64-bit, machine endian
        return (int)$arr[1];
    }

    /** Znajdź indeks w entries według targetu ważonego (cumulative scan) */
    private function findWeightedIndex(array $entries, int $target): int
    {
        $acc = 0;
        $lastIdx = 0;
        foreach ($entries as $i => $e) {
            $w = max(1, (int)($e['weight'] ?? 1));
            $acc += $w;
            if ($target < $acc) return $i;
            $lastIdx = $i;
        }
        return $lastIdx; // fallback
    }
}
