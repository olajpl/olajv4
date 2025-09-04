<?php
// engine/stock/StockReservationEngine.php — Olaj.pl V4
declare(strict_types=1);

namespace Engine\Stock;

use PDO;
use Throwable;
use RuntimeException;
use Engine\Enum\Column;
use Engine\Enum\StockReservationStatus;
use Engine\Enum\EnumValidator;

if (!\function_exists('logg')) {
    function logg(string $level, string $channel, string $message, array $context = [], array $extra = []): void
    {
        error_log('[logg-fallback] ' . json_encode(compact('level', 'channel', 'message', 'context', 'extra'), JSON_UNESCAPED_UNICODE));
    }
}

final class StockReservationEngine
{
    public function __construct(private PDO $pdo) {}

    public function reserve(int $ownerId, int $productId, int $clientId, int $liveId, float $qty, int $sourceRowId): int
    {
        EnumValidator::assert(StockReservationStatus::class, 'reserved');

        $qty = $this->toDecimal($qty);
        $this->pdo->beginTransaction();

        try {
            // Zwiększ stock_reserved
            $this->pdo->prepare("UPDATE products SET stock_reserved = stock_reserved + :qty WHERE id = :pid AND owner_id = :oid")
                ->execute(['qty' => $qty, 'pid' => $productId, 'oid' => $ownerId]);

            // INSERT rezerwacji
            $stmt = $this->pdo->prepare(
                "INSERT INTO stock_reservations (owner_id, product_id, client_id, live_id, qty, status, source, source_row_id, reserved_at)
                 VALUES (:oid, :pid, :cid, :lid, :qty, 'reserved', 'live', :src, NOW())"
            );
            $stmt->execute([
                'oid' => $ownerId,
                'pid' => $productId,
                'cid' => $clientId,
                'lid' => $liveId,
                'qty' => $qty,
                'src' => $sourceRowId,
            ]);

            $resId = (int)$this->pdo->lastInsertId();

            $this->pdo->commit();

            logg('info', 'stock.reservation', 'reserved', compact(
                'ownerId',
                'productId',
                'clientId',
                'qty',
                'liveId',
                'sourceRowId',
                'resId'
            ));

            return $resId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            logg('error', 'stock.reservation', 'reserve.error', ['ex' => $e->getMessage()]);
            throw $e;
        }
    }

    public function release(int $ownerId, int $reservationId): void
    {
        EnumValidator::assert(StockReservationStatus::class, 'released');

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                "SELECT product_id, qty
                   FROM stock_reservations
                  WHERE id = :id AND owner_id = :oid AND status = 'reserved'
                  FOR UPDATE"
            );
            $st->execute(['id' => $reservationId, 'oid' => $ownerId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Reservation not found or already processed');

            $productId = (int)$row['product_id'];
            $qty       = $this->toDecimal((float)$row['qty']);

            $this->pdo->prepare("UPDATE products SET stock_reserved = stock_reserved - :qty WHERE id = :pid AND owner_id = :oid")
                ->execute(['qty' => $qty, 'pid' => $productId, 'oid' => $ownerId]);

            $this->pdo->prepare("UPDATE stock_reservations SET status = 'released', released_at = NOW() WHERE id = :id")
                ->execute(['id' => $reservationId]);

            $this->pdo->commit();

            logg('info', 'stock.reservation', 'released', compact('ownerId', 'reservationId', 'productId', 'qty'));
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            logg('error', 'stock.reservation', 'release.error', ['ex' => $e->getMessage()]);
            throw $e;
        }
    }

    public function commit(int $ownerId, int $reservationId): void
    {
        EnumValidator::assert(StockReservationStatus::class, 'committed');

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                "SELECT product_id, qty
                   FROM stock_reservations
                  WHERE id = :id AND owner_id = :oid AND status = 'reserved'
                  FOR UPDATE"
            );
            $st->execute(['id' => $reservationId, 'oid' => $ownerId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Reservation not found or already processed');

            $productId = (int)$row['product_id'];
            $qty       = $this->toDecimal((float)$row['qty']);

            // Zmniejsz stock i stock_reserved
            $this->pdo->prepare("UPDATE products
                                    SET stock = stock - :qty, stock_reserved = stock_reserved - :qty
                                  WHERE id = :pid AND owner_id = :oid")
                ->execute(['qty' => $qty, 'pid' => $productId, 'oid' => $ownerId]);

            $this->pdo->prepare("UPDATE stock_reservations
                                    SET status = 'committed', committed_at = NOW()
                                  WHERE id = :id")
                ->execute(['id' => $reservationId]);

            $this->pdo->commit();

            logg('info', 'stock.reservation', 'committed', compact('ownerId', 'reservationId', 'productId', 'qty'));
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            logg('error', 'stock.reservation', 'commit.error', ['ex' => $e->getMessage()]);
            throw $e;
        }
    }

    private function toDecimal(float $v): float
    {
        return (float)number_format($v, 3, '.', '');
    }
}
