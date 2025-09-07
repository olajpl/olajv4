<?php
declare(strict_types=1);

namespace Engine\Stock;

use PDO;
use PDOException;
use Throwable;

final class StockReservationEngine
{
    private PDO $pdo;
    private int $ownerId;

    public function __construct(PDO $pdo, int $ownerId)
    {
        $this->pdo     = $pdo;
        $this->ownerId = $ownerId;

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function boot(PDO $pdo, int $ownerId): self
    {
        return new self($pdo, $ownerId);
    }

    /* =========================
     * API instancyjne
     * ========================= */

    public function reserve(
        int $productId,
        float $qty,
        string $sourceType,   // 'live' | 'manual'
        int $sourceRowId,     // <-- dopasowane do kolumny source_row_id
        ?int $clientId = null,
        ?int $liveId = null
    ): int {
        return self::create(
            $this->pdo,
            $this->ownerId,
            $productId,
            (int)($clientId ?? 0),
            (float)$qty,
            $sourceType,
            $sourceRowId,
            $liveId
        );
    }

    public function commitReservationBySource(string $sourceType, int $sourceRowId): bool
    {
        return self::commitBySource($this->pdo, $this->ownerId, $sourceType, $sourceRowId);
    }

    public function releaseBySource(string $sourceType, int $sourceRowId): bool
    {
        return self::releaseBySourceStatic($this->pdo, $this->ownerId, $sourceType, $sourceRowId);
    }

    /* =========================
     * API statyczne (kompat)
     * ========================= */

    /**
     * Tworzy rezerwację (status='reserved') i przelicza cache w `products`.
     * Zwraca ID rezerwacji.
     * ZGODNE ZE SCHEMATEM:
     *   stock_reservations(source_type, source_row_id, live_id, ...)
     */
    public static function create(
        PDO $pdo,
        int $ownerId,
        int $productId,
        int $clientId,
        float $qty,
        string $sourceType,
        int $sourceRowId,
        ?int $liveId = null
    ): int {
        if ($qty <= 0) throw new \InvalidArgumentException('qty_must_be_positive');

        $weStartedTx = false;
        try {
            if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $weStartedTx = true; }

            $stmt = $pdo->prepare("
                INSERT INTO stock_reservations
                    (product_id, owner_id, client_id, live_id, qty, status, source_type, source_row_id, note, flags, metadata, created_at, reserved_at)
                VALUES
                    (:pid, :oid, :cid, :live_id, :qty, 'reserved', :stype, :srow, '', '', NULL, NOW(), NOW())
            ");
            $stmt->execute([
                ':pid'     => $productId,
                ':oid'     => $ownerId,
                ':cid'     => $clientId ?: null,
                ':live_id' => $liveId ?: null,
                ':qty'     => $qty,
                ':stype'   => $sourceType,
                ':srow'    => $sourceRowId,
            ]);

            $id = (int)$pdo->lastInsertId();

            self::recalculateReservedCache($pdo, $ownerId, $productId);

            if ($weStartedTx) $pdo->commit();

            if (\function_exists('logg')) {
                logg('info', 'stock.reserve', 'reserved', [
                    'reservation_id' => $id,
                    'owner_id'       => $ownerId,
                    'product_id'     => $productId,
                    'qty'            => $qty,
                    'source_type'    => $sourceType,
                    'source_row_id'  => $sourceRowId,
                    'live_id'        => $liveId,
                ], ['context' => 'stock']);
            }

            return $id;
        } catch (Throwable $e) {
            if ($weStartedTx && $pdo->inTransaction()) $pdo->rollBack();
            if (\function_exists('logg')) logg('error', 'stock.reserve', 'exception', ['ex' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Commit rezerwacji po jej ID (reserved -> committed).
     */
    public static function commit(PDO $pdo, int $reservationId): bool
    {
        $weStartedTx = false;
        try {
            if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $weStartedTx = true; }

            $row = self::getReservation($pdo, $reservationId);
            if (!$row || ($row['status'] ?? '') !== 'reserved') {
                if ($weStartedTx && $pdo->inTransaction()) $pdo->rollBack();
                return false;
            }

            $pdo->prepare("
                UPDATE stock_reservations
                   SET status='committed', committed_at = NOW()
                 WHERE id = :id AND status='reserved'
            ")->execute([':id' => $reservationId]);

            self::recalculateReservedCache($pdo, (int)$row['owner_id'], (int)$row['product_id']);

            if ($weStartedTx) $pdo->commit();

            if (\function_exists('logg')) {
                logg('info', 'stock.commit', 'reservation_committed', [
                    'reservation_id' => $reservationId,
                    'product_id'     => (int)$row['product_id'],
                    'owner_id'       => (int)$row['owner_id'],
                ]);
            }

            return true;
        } catch (Throwable $e) {
            if ($weStartedTx && $pdo->inTransaction()) $pdo->rollBack();
            if (\function_exists('logg')) logg('error', 'stock.commit', 'exception', ['ex' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Commit wszystkich rezerwacji powiązanych z (source_type, source_row_id).
     */
    public static function commitBySource(PDO $pdo, int $ownerId, string $sourceType, int $sourceRowId): bool
    {
        $weStartedTx = false;
        try {
            if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $weStartedTx = true; }

            $st = $pdo->prepare("
                SELECT id, product_id
                  FROM stock_reservations
                 WHERE owner_id = :oid
                   AND source_type = :stype
                   AND source_row_id = :srow
                   AND status = 'reserved'
                 FOR UPDATE
            ");
            $st->execute([':oid' => $ownerId, ':stype' => $sourceType, ':srow' => $sourceRowId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) { if ($weStartedTx) $pdo->commit(); return true; }

            $up = $pdo->prepare("
                UPDATE stock_reservations
                   SET status='committed', committed_at = NOW()
                 WHERE id = :id
            ");
            $touched = [];
            foreach ($rows as $r) {
                $up->execute([':id' => (int)$r['id']]);
                $touched[(int)$r['product_id']] = true;
            }

            foreach (array_keys($touched) as $pid) {
                self::recalculateReservedCache($pdo, $ownerId, (int)$pid);
            }

            if ($weStartedTx) $pdo->commit();

            if (\function_exists('logg')) {
                logg('info', 'stock.commit', 'by_source', [
                    'owner_id'      => $ownerId,
                    'source_type'   => $sourceType,
                    'source_row_id' => $sourceRowId,
                    'count'         => count($rows)
                ]);
            }

            return true;
        } catch (Throwable $e) {
            if ($weStartedTx && $pdo->inTransaction()) $pdo->rollBack();
            if (\function_exists('logg')) logg('error', 'stock.commit', 'exception', ['ex' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Release (anuluj) wszystkie rezerwacje po (source_type, source_row_id).
     */
    public static function releaseBySourceStatic(PDO $pdo, int $ownerId, string $sourceType, int $sourceRowId): bool
    {
        $weStartedTx = false;
        try {
            if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $weStartedTx = true; }

            $st = $pdo->prepare("
                SELECT id, product_id
                  FROM stock_reservations
                 WHERE owner_id = :oid
                   AND source_type = :stype
                   AND source_row_id = :srow
                   AND status = 'reserved'
                 FOR UPDATE
            ");
            $st->execute([':oid' => $ownerId, ':stype' => $sourceType, ':srow' => $sourceRowId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) { if ($weStartedTx) $pdo->commit(); return true; }

            $up = $pdo->prepare("
                UPDATE stock_reservations
                   SET status='released', released_at = NOW()
                 WHERE id = :id
            ");
            $touched = [];
            foreach ($rows as $r) {
                $up->execute([':id' => (int)$r['id']]);
                $touched[(int)$r['product_id']] = true;
            }

            foreach (array_keys($touched) as $pid) {
                self::recalculateReservedCache($pdo, $ownerId, (int)$pid);
            }

            if ($weStartedTx) $pdo->commit();

            if (\function_exists('logg')) {
                logg('info', 'stock.release', 'by_source', [
                    'owner_id'      => $ownerId,
                    'source_type'   => $sourceType,
                    'source_row_id' => $sourceRowId,
                    'count'         => count($rows)
                ]);
            }

            return true;
        } catch (Throwable $e) {
            if ($weStartedTx && $pdo->inTransaction()) $pdo->rollBack();
            if (\function_exists('logg')) logg('error', 'stock.release', 'exception', ['ex' => $e->getMessage()]);
            throw $e;
        }
    }

    /* ======================
     * Helpers
     * ====================== */

    private static function getReservation(PDO $pdo, int $reservationId): ?array
    {
        $st = $pdo->prepare("SELECT * FROM stock_reservations WHERE id = :id LIMIT 1");
        $st->execute([':id' => $reservationId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private static function recalculateReservedCache(PDO $pdo, int $ownerId, int $productId): void
    {
        $sumSt = $pdo->prepare("
            SELECT COALESCE(SUM(qty),0) AS s
              FROM stock_reservations
             WHERE owner_id = :oid
               AND product_id = :pid
               AND status = 'reserved'
        ");
        $sumSt->execute([':oid' => $ownerId, ':pid' => $productId]);
        $sum = (float)$sumSt->fetchColumn();

        try {
            $upd = $pdo->prepare("
                UPDATE products
                   SET stock_reserved_cached = :s,
                       updated_at = NOW()
                 WHERE id = :pid AND owner_id = :oid
            ");
            $upd->execute([':s' => $sum, ':pid' => $productId, ':oid' => $ownerId]);
        } catch (PDOException $e) {
            if (\function_exists('logg')) {
                logg('warning', 'stock.cache', 'reserved_cache_update_skipped', [
                    'owner_id'   => $ownerId,
                    'product_id' => $productId,
                    'reason'     => $e->getMessage()
                ]);
            }
        }
    }
}
