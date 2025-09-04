<?php
// engine/stock/StockEngine.php
declare(strict_types=1);

namespace Engine\Stock;

use PDO;
use Throwable;
use RuntimeException;

final class StockEngine
{
    public function __construct(private PDO $pdo) {}

    /* ───────────────────────────── Const ───────────────────────────── */

    private const RES_STATUS_RESERVED  = 'reserved';
    private const RES_STATUS_RELEASED  = 'released';
    private const RES_STATUS_COMMITTED = 'committed';

    // Typy ruchów w stock_movements (przykładowe – dopasuj do swojej tabeli)
    private const MOVE_IN       = 'in';
    private const MOVE_OUT      = 'out';
    private const MOVE_RESERVE  = 'reserve';
    private const MOVE_UNRESERVE = 'unreserve';
    private const MOVE_COMMIT   = 'commit';

    /* ───────────────────────────── Locks (advisory) ───────────────────────────── */

    public function acquireLock(int $productId, int $timeoutSec = 3): bool
    {
        $st = $this->pdo->prepare("SELECT GET_LOCK(:name, :t) AS locked");
        $st->execute(['name' => "stock:pid:{$productId}", 't' => $timeoutSec]);
        return (bool)($st->fetch(PDO::FETCH_ASSOC)['locked'] ?? false);
    }

    public function releaseLock(int $productId): void
    {
        $st = $this->pdo->prepare("SELECT RELEASE_LOCK(:name) AS released");
        $st->execute(['name' => "stock:pid:{$productId}"]);
        // advisory – brak wyjątków
    }

    /* ───────────────────────────── Availability ───────────────────────────── */

    /** Opcjonalnie możesz przekazać $ownerId, żeby pilnować multi-tenant. */
    public function checkAvailability(int $productId, ?int $ownerId = null): array
    {
        $sql = "SELECT stock, stock_reserved FROM products WHERE id = :pid"
            . ($ownerId ? " AND owner_id = :oid" : "")
            . " LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $params = ['pid' => $productId] + ($ownerId ? ['oid' => $ownerId] : []);
        $st->execute($params);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException("Produkt nie istnieje (ID: {$productId})");
        }

        $stock     = (int)$row['stock'];
        $reserved  = (int)$row['stock_reserved'];
        $available = max(0, $stock - $reserved);

        return compact('stock', 'reserved', 'available');
    }

    /* ───────────────────────────── Reserve (core) ───────────────────────────── */

    /**
     * Tworzy rezerwację ('reserved') i inkrementuje products.stock_reserved.
     * Zwraca reservation_id.
     */
    public function reserve(
        int $productId,
        int $clientId,
        int $ownerId,
        int $qty,
        string $source,
        ?int $sourceRowId = null,
        bool $useAdvisoryLock = true
    ): int {
        if ($qty <= 0) {
            throw new RuntimeException("Nieprawidłowa ilość do rezerwacji: {$qty}");
        }

        $locked = false;
        if ($useAdvisoryLock) {
            $locked = $this->acquireLock($productId, 3);
            if (!$locked) {
                throw new RuntimeException("Nie udało się uzyskać blokady dla produktu {$productId}");
            }
        }

        $this->pdo->beginTransaction();
        try {
            // Zabezpieczenie tenantem i blokadą wiersza
            $st = $this->pdo->prepare("SELECT stock, stock_reserved FROM products WHERE id=:pid AND owner_id=:oid FOR UPDATE");
            $st->execute(['pid' => $productId, 'oid' => $ownerId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException("Produkt nie istnieje lub owner mismatch (ID: {$productId})");
            }

            $available = (int)$row['stock'] - (int)$row['stock_reserved'];
            if ($available < $qty) {
                throw new RuntimeException("Brak wystarczającej ilości (dostępne: {$available}, potrzebne: {$qty})");
            }

            // Insert rezerwacji
            $ins = $this->pdo->prepare("
                INSERT INTO stock_reservations
                    (product_id, client_id, owner_id, qty, source, source_row_id, status, reserved_at)
                VALUES
                    (:pid, :cid, :oid, :qty, :src, :src_row, :status, NOW())
            ");
            $ins->execute([
                'pid'     => $productId,
                'cid'     => $clientId,
                'oid'     => $ownerId,
                'qty'     => $qty,
                'src'     => $source,
                'src_row' => $sourceRowId,
                'status'  => self::RES_STATUS_RESERVED,
            ]);
            $reservationId = (int)$this->pdo->lastInsertId();

            // Aktualizacja products.stock_reserved
            $upd = $this->pdo->prepare("UPDATE products SET stock_reserved = stock_reserved + :q WHERE id=:pid AND owner_id=:oid");
            $upd->execute(['q' => $qty, 'pid' => $productId, 'oid' => $ownerId]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException("Nie zaktualizowano stock_reserved (pid={$productId}, oid={$ownerId})");
            }

            // Ruch magazynowy (rezerwacja logiczna)
            $this->insertMovement($ownerId, $productId, self::MOVE_RESERVE, $qty, $source, $sourceRowId, [
                'reservation_id' => $reservationId,
                'client_id'      => $clientId,
            ]);

            \logg('info', 'stock', 'reserve.created', [
                'reservation_id' => $reservationId,
                'product_id'     => $productId,
                'client_id'      => $clientId,
                'owner_id'       => $ownerId,
                'qty'            => $qty,
                'source'         => $source,
                'source_row_id'  => $sourceRowId,
            ]);

            $this->pdo->commit();
            return $reservationId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            \logg('error', 'stock', 'reserve.failed', [
                'product_id' => $productId,
                'owner_id'   => $ownerId,
                'qty'        => $qty,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if ($locked) $this->releaseLock($productId);
        }
    }

    /* ───────────────────────────── Commit ───────────────────────────── */

    /**
     * Commit rezerwacji: products.stock–, products.stock_reserved–, reservations.status='committed'.
     * Zalecane wywołanie przy finalizacji (np. finalizeBatch LIVE).
     */
    public function commit(int $reservationId, int $ownerId, bool $useAdvisoryLock = true): void
    {
        $head = $this->fetchReservation($reservationId, $ownerId);
        $productId = (int)$head['product_id'];
        $qty       = (int)$head['qty'];

        $locked = false;
        if ($useAdvisoryLock) {
            $locked = $this->acquireLock($productId, 3);
            if (!$locked) {
                throw new RuntimeException("Nie udało się uzyskać blokady dla produktu {$productId}");
            }
        }

        $this->pdo->beginTransaction();
        try {
            $res = $this->pdo->prepare("SELECT * FROM stock_reservations WHERE id=:id AND owner_id=:oid FOR UPDATE");
            $res->execute(['id' => $reservationId, 'oid' => $ownerId]);
            $row = $res->fetch(PDO::FETCH_ASSOC);

            if (!$row || $row['status'] !== self::RES_STATUS_RESERVED) {
                throw new RuntimeException('Rezerwacja nie istnieje lub nie jest aktywna (reserved)');
            }

            // Aktualizacje produktów
            $upd = $this->pdo->prepare("
                UPDATE products
                   SET stock = stock - :q,
                       stock_reserved = GREATEST(0, stock_reserved - :q)
                 WHERE id = :pid AND owner_id=:oid AND stock >= :q
            ");
            $upd->execute(['q' => $qty, 'pid' => $productId, 'oid' => $ownerId]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException("Brak zapasu do commit (pid={$productId}, q={$qty})");
            }

            // Zmiana statusu rezerwacji
            $rsu = $this->pdo->prepare("
                UPDATE stock_reservations
                   SET status=:st, committed_at=NOW()
                 WHERE id=:id AND owner_id=:oid
            ");
            $rsu->execute(['st' => self::RES_STATUS_COMMITTED, 'id' => $reservationId, 'oid' => $ownerId]);

            // Ruch magazynowy (fizyczne zdjęcie stanu)
            $this->insertMovement($ownerId, $productId, self::MOVE_COMMIT, $qty, $row['source'] ?? '', (int)($row['source_row_id'] ?? 0), [
                'reservation_id' => $reservationId,
                'client_id'      => (int)($row['client_id'] ?? 0),
            ]);

            \logg('info', 'stock', 'reserve.committed', [
                'reservation_id' => $reservationId,
                'product_id'     => $productId,
                'qty'            => $qty,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            \logg('error', 'stock', 'commit.failed', [
                'reservation_id' => $reservationId,
                'error'          => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if ($locked) $this->releaseLock($productId);
        }
    }

    /** Commit po `source_row_id` (np. live_temp.id lub order_item.id) */
    public function commitBySourceRow(string $source, int $sourceRowId, int $ownerId): void
    {
        $st = $this->pdo->prepare("SELECT id FROM stock_reservations WHERE owner_id=:oid AND source=:src AND source_row_id=:rid AND status=:st");
        $st->execute(['oid' => $ownerId, 'src' => $source, 'rid' => $sourceRowId, 'st' => self::RES_STATUS_RESERVED]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $rid) {
            $this->commit((int)$rid, $ownerId, true);
        }
    }

    /* ───────────────────────────── Release ───────────────────────────── */

    /**
     * Release rezerwacji: products.stock_reserved–, reservations.status='released'.
     * Używaj przy usunięciu z live_temp lub anulacji.
     */
    public function release(int $reservationId, int $ownerId, bool $useAdvisoryLock = true): void
    {
        $head = $this->fetchReservation($reservationId, $ownerId);
        $productId = (int)$head['product_id'];
        $qty       = (int)$head['qty'];

        $locked = false;
        if ($useAdvisoryLock) {
            $locked = $this->acquireLock($productId, 3);
            if (!$locked) {
                throw new RuntimeException("Nie udało się uzyskać blokady dla produktu {$productId}");
            }
        }

        $this->pdo->beginTransaction();
        try {
            $res = $this->pdo->prepare("SELECT * FROM stock_reservations WHERE id=:id AND owner_id=:oid FOR UPDATE");
            $res->execute(['id' => $reservationId, 'oid' => $ownerId]);
            $row = $res->fetch(PDO::FETCH_ASSOC);

            if (!$row || $row['status'] !== self::RES_STATUS_RESERVED) {
                throw new RuntimeException('Rezerwacja nie istnieje lub nie jest już aktywna');
            }

            $upd = $this->pdo->prepare("
                UPDATE products
                   SET stock_reserved = GREATEST(0, stock_reserved - :q)
                 WHERE id = :pid AND owner_id=:oid
            ");
            $upd->execute(['q' => $qty, 'pid' => $productId, 'oid' => $ownerId]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException("Nie zaktualizowano stock_reserved przy release (pid={$productId})");
            }

            $this->pdo->prepare("
                UPDATE stock_reservations
                   SET status=:st, released_at=NOW()
                 WHERE id=:id AND owner_id=:oid
            ")->execute(['st' => self::RES_STATUS_RELEASED, 'id' => $reservationId, 'oid' => $ownerId]);

            // Ruch magazynowy (zdjęcie rezerwacji logicznej)
            $this->insertMovement($ownerId, $productId, self::MOVE_UNRESERVE, $qty, $row['source'] ?? '', (int)($row['source_row_id'] ?? 0), [
                'reservation_id' => $reservationId,
                'client_id'      => (int)($row['client_id'] ?? 0),
            ]);

            \logg('info', 'stock', 'reserve.released', [
                'reservation_id' => $reservationId,
                'product_id'     => $productId,
                'qty'            => $qty,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            \logg('error', 'stock', 'release.failed', [
                'reservation_id' => $reservationId,
                'error'          => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if ($locked) $this->releaseLock($productId);
        }
    }

    /** Release po `source_row_id` (np. usunięcie z live_temp) */
    public function releaseBySourceRow(string $source, int $sourceRowId, int $ownerId): void
    {
        $st = $this->pdo->prepare("SELECT id FROM stock_reservations WHERE owner_id=:oid AND source=:src AND source_row_id=:rid AND status=:st");
        $st->execute(['oid' => $ownerId, 'src' => $source, 'rid' => $sourceRowId, 'st' => self::RES_STATUS_RESERVED]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $rid) {
            $this->release((int)$rid, $ownerId, true);
        }
    }

    /* ───────────────────────────── Adjust stock ───────────────────────────── */

    /**
     * Korekta zapasu (np. przyjęcie dostawy lub inwentaryzacja).
     * $type: 'in' zwiększa stock, 'out' zmniejsza.
     */
    public function adjustStock(
        int $ownerId,
        int $productId,
        int $qty,
        string $type = self::MOVE_IN,
        string $source = 'manual',
        ?int $sourceRowId = null,
        bool $useAdvisoryLock = true
    ): void {
        if ($qty <= 0) throw new RuntimeException("Nieprawidłowa ilość: {$qty}");
        if (!\in_array($type, [self::MOVE_IN, self::MOVE_OUT], true)) {
            throw new RuntimeException("Nieprawidłowy typ ruchu: {$type}");
        }

        $locked = false;
        if ($useAdvisoryLock) {
            $locked = $this->acquireLock($productId, 3);
            if (!$locked) throw new RuntimeException("Brak locka dla produktu {$productId}");
        }

        $this->pdo->beginTransaction();
        try {
            // FOR UPDATE by uniknąć wyścigów
            $st = $this->pdo->prepare("SELECT stock FROM products WHERE id=:pid AND owner_id=:oid FOR UPDATE");
            $st->execute(['pid' => $productId, 'oid' => $ownerId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException("Produkt nie istnieje lub owner mismatch");

            if ($type === self::MOVE_IN) {
                $upd = $this->pdo->prepare("UPDATE products SET stock = stock + :q WHERE id=:pid AND owner_id=:oid");
                $upd->execute(['q' => $qty, 'pid' => $productId, 'oid' => $ownerId]);
            } else {
                $upd = $this->pdo->prepare("UPDATE products SET stock = stock - :q WHERE id=:pid AND owner_id=:oid AND stock >= :q");
                $upd->execute(['q' => $qty, 'pid' => $productId, 'oid' => $ownerId]);
                if ($upd->rowCount() !== 1) {
                    throw new RuntimeException("Za mało stanu do zdjęcia (pid={$productId}, q={$qty})");
                }
            }

            $this->insertMovement($ownerId, $productId, $type, $qty, $source, $sourceRowId);

            \logg('info', 'stock', 'stock.adjust', [
                'product_id'   => $productId,
                'owner_id'     => $ownerId,
                'qty'          => $qty,
                'type'         => $type,
                'source'       => $source,
                'source_row_id' => $sourceRowId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            \logg('error', 'stock', 'stock.adjust_failed', [
                'product_id' => $productId,
                'owner_id'   => $ownerId,
                'qty'        => $qty,
                'type'       => $type,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if ($locked) $this->releaseLock($productId);
        }
    }

    /* ───────────────────────────── Helpers higher-level ───────────────────────────── */

    /** Alias: rezerwacja dla LIVE (source='live', source_row_id = live_temp.id) */
    public function reserveForLive(int $productId, int $clientId, int $ownerId, int $qty, int $liveTempId): int
    {
        return $this->reserve($productId, $clientId, $ownerId, $qty, 'live', $liveTempId, true);
    }

    /** Alias: rezerwacja pod pozycję zamówienia (source='order_item', source_row_id = order_items.id) */
    public function reserveForOrderItem(int $productId, int $clientId, int $ownerId, int $qty, int $orderItemId): int
    {
        return $this->reserve($productId, $clientId, $ownerId, $qty, 'order_item', $orderItemId, true);
    }

    /** Commit wielu rezerwacji w jednej transakcji (np. finalizeBatch) */
    public function commitMany(array $reservationIds, int $ownerId): void
    {
        if (!$reservationIds) return;

        // Zbierz product_id do locków (minimalizacja deadlocków: sortuj)
        $meta = $this->pdo->prepare("SELECT id, product_id FROM stock_reservations WHERE owner_id=:oid AND id IN (" . implode(',', array_map('intval', $reservationIds)) . ")");
        $meta->execute(['oid' => $ownerId]);
        $rows = $meta->fetchAll(PDO::FETCH_ASSOC);
        $locks = [];
        foreach ($rows as $r) $locks[(int)$r['product_id']] = true;

        $locked = [];
        try {
            foreach (array_keys($locks) as $pid) {
                if ($this->acquireLock($pid, 3)) $locked[] = $pid;
                else throw new RuntimeException("Brak locka dla produktu {$pid}");
            }

            $this->pdo->beginTransaction();
            try {
                foreach ($reservationIds as $rid) {
                    // commit() ma własną transakcję – tu wołamy wewnętrznie „lokalnie”
                    // więc zrobimy tu inline: uproszczone powtórzenie logiki commit()
                    $head = $this->fetchReservation((int)$rid, $ownerId);
                    if ($head['status'] !== self::RES_STATUS_RESERVED) {
                        throw new RuntimeException("Rezerwacja nieaktywna rid={$rid}");
                    }
                    $pid = (int)$head['product_id'];
                    $q   = (int)$head['qty'];

                    $res = $this->pdo->prepare("SELECT * FROM stock_reservations WHERE id=:id AND owner_id=:oid FOR UPDATE");
                    $res->execute(['id' => $rid, 'oid' => $ownerId]);
                    $row = $res->fetch(PDO::FETCH_ASSOC);

                    $upd = $this->pdo->prepare("
                        UPDATE products
                           SET stock = stock - :q,
                               stock_reserved = GREATEST(0, stock_reserved - :q)
                         WHERE id = :pid AND owner_id=:oid AND stock >= :q
                    ");
                    $upd->execute(['q' => $q, 'pid' => $pid, 'oid' => $ownerId]);
                    if ($upd->rowCount() !== 1) {
                        throw new RuntimeException("Brak zapasu do commit (pid={$pid}, q={$q})");
                    }

                    $this->pdo->prepare("
                        UPDATE stock_reservations
                           SET status=:st, committed_at=NOW()
                         WHERE id=:id AND owner_id=:oid
                    ")->execute(['st' => self::RES_STATUS_COMMITTED, 'id' => $rid, 'oid' => $ownerId]);

                    $this->insertMovement($ownerId, $pid, self::MOVE_COMMIT, $q, $row['source'] ?? '', (int)($row['source_row_id'] ?? 0), [
                        'reservation_id' => (int)$rid,
                        'client_id'      => (int)($row['client_id'] ?? 0),
                    ]);
                }

                $this->pdo->commit();
                \logg('info', 'stock', 'commit.batch_ok', ['count' => count($reservationIds), 'owner_id' => $ownerId]);
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                \logg('error', 'stock', 'commit.batch_failed', [
                    'count' => count($reservationIds),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        } finally {
            foreach ($locked as $pid) $this->releaseLock($pid);
        }
    }

    /* ───────────────────────────── Low-level helpers ───────────────────────────── */

    private function fetchReservation(int $reservationId, int $ownerId): array
    {
        $h = $this->pdo->prepare("
            SELECT id, product_id, qty, status, source, source_row_id, client_id
              FROM stock_reservations
             WHERE id=:id AND owner_id=:oid
             LIMIT 1
        ");
        $h->execute(['id' => $reservationId, 'oid' => $ownerId]);
        $row = $h->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Nie znaleziono rezerwacji lub owner mismatch');
        }
        return $row;
    }

    /**
     * Zapisuje ruch w `stock_movements`.
     * Przyjmuję minimalny schemat: owner_id, product_id, type, qty, source, source_row_id, created_at, metadata_json
     */
    private function insertMovement(
        int $ownerId,
        int $productId,
        string $type,
        int $qty,
        string $source = 'system',
        ?int $sourceRowId = null,
        array $meta = []
    ): void {
        try {
            $st = $this->pdo->prepare("
                INSERT INTO stock_movements
                    (owner_id, product_id, type, qty, source, source_row_id, metadata_json, created_at)
                VALUES
                    (:oid, :pid, :type, :qty, :src, :src_row, :meta, NOW())
            ");
            $st->execute([
                'oid'     => $ownerId,
                'pid'     => $productId,
                'type'    => $type,
                'qty'     => $qty,
                'src'     => $source,
                'src_row' => $sourceRowId,
                'meta'    => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            \logg('error', 'stock', 'movement.insert_failed', [
                'product_id' => $productId,
                'owner_id'   => $ownerId,
                'type'       => $type,
                'qty'        => $qty,
                'error'      => $e->getMessage(),
            ]);
            // Nie przerywamy głównej operacji – ruch jest wtórny do stanu produktów
        }
    }
}
