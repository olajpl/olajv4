<?php
// engine/Live/LiveEngine.php — Olaj.pl V4 (LIVE + Stock + Rezerwacje + Finalizacja)
declare(strict_types=1);

namespace Engine\Live;

use PDO;
use Throwable;
use RuntimeException;
use Engine\Log\LogEngine;
use Engine\Enum\OrderItemSourceType;
use Engine\Stock\StockReservationEngine;
use Engine\Orders\OrderEngine;

final class LiveEngine
{
    private PDO $pdo;
    private int $ownerId;

    public function __construct(PDO $pdo, int $ownerId)
    {
        $this->pdo     = $pdo;
        $this->ownerId = $ownerId;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        LogEngine::boot($this->pdo, $this->ownerId)->debug('live.engine', 'Boot', [], ['context' => 'live']);
    }

    public static function boot(PDO $pdo, int $ownerId): self
    {
        return new self($pdo, $ownerId);
    }

    /**
     * Dodaje pozycję do live_temp.
     * Jeśli to produkt katalogowy, robi snapshot name/sku/price/vat z products,
     * gdy pola z frontu są puste.
     */
    public function addProduct(
    int $liveId,
    int $clientId,
    ?int $productId,
    string $name,
    float $qty,
    ?int $groupId = null,
    ?string $sku = null,
    ?float $price = null,
    ?float $vatRate = null,
    ?int $operatorUserId = null
): void {
    $this->pdo->beginTransaction();
    try {
        // 1) Normalizacja „pustych” wartości
        $snapName  = trim($name ?? '');
        $snapSku   = is_string($sku) ? trim($sku) : null;
        $snapPrice = $price;
        $snapVat   = $vatRate;

        // helper: czy nazwa to placeholder typu "Produkt", "Product", itp.
        $isPlaceholder = function(string $s): bool {
            $s = mb_strtolower(trim($s));
            return $s === '' || in_array($s, ['produkt', 'product', 'item', 'towar'], true);
        };

        // 2) Snapshot z products dla katalogowych (tylko gdy braki / placeholdery)
        if ($productId !== null && $productId > 0) {
            $ps = $this->pdo->prepare("
                SELECT name, sku, unit_price, vat_rate
                  FROM products
                 WHERE id = :pid AND owner_id = :oid
                 LIMIT 1
            ");
            $ps->execute([':pid' => $productId, ':oid' => $this->ownerId]);
            if ($prod = $ps->fetch(PDO::FETCH_ASSOC)) {
                if ($isPlaceholder($snapName))                   $snapName  = (string)($prod['name'] ?? '');
                if ($snapSku === null || $snapSku === '')        $snapSku   = (string)($prod['sku'] ?? '');
                if ($snapPrice === null)                         $snapPrice = isset($prod['unit_price']) ? (float)$prod['unit_price'] : null;
                if ($snapVat   === null)                         $snapVat   = isset($prod['vat_rate'])   ? (float)$prod['vat_rate']   : null;
            }
        }

        // 3) Domyślne VAT/cena
        if ($snapVat === null)   $snapVat = 23.0;
        if ($snapPrice === null) $snapPrice = 0.0;

        // 4) INSERT do live_temp (ze znormalizowanymi polami)
        $stmt = $this->pdo->prepare("
            INSERT INTO live_temp
                (owner_id, live_id, client_id, operator_user_id,
                 product_id, name, sku, qty, group_id, price, vat_rate,
                 source_type, reservation_id, transferred_at,
                 target_order_id, target_group_id, batch_id, flags, note, metadata, created_at)
            VALUES
                (:oid, :live, :cid, :opid,
                 :pid, :name, :sku, :qty, :gid, :price, :vat,
                 :stype, NULL, NULL,
                 NULL, NULL, NULL, '', NULL, NULL, NOW())
        ");
        $stmt->execute([
            ':oid'   => $this->ownerId,
            ':live'  => $liveId,
            ':cid'   => $clientId,
            ':opid'  => $operatorUserId,
            ':pid'   => $productId,
            ':name'  => $snapName,
            ':sku'   => $snapSku,
            ':qty'   => $qty,
            ':gid'   => $groupId,
            ':price' => $snapPrice,
            ':vat'   => $snapVat,
            ':stype' => ($productId !== null ? 'catalog' : 'custom'),
        ]);

        $liveTempId = (int)$this->pdo->lastInsertId();

        // 5) Rezerwacja tylko dla katalogowych
        if ($productId !== null) {
            $reservationId = \Engine\Stock\StockReservationEngine::create(
                $this->pdo,
                $this->ownerId,
                $productId,
                $clientId,
                (int)$qty,
                'live',
                $liveTempId,
                $liveId
            );
            $this->pdo->prepare("UPDATE live_temp SET reservation_id = :rid WHERE id = :id")
                ->execute([':rid' => $reservationId, ':id' => $liveTempId]);
        }

        $this->pdo->commit();
        \Engine\Log\LogEngine::boot($this->pdo, $this->ownerId)->info('live.add', 'ok', [
            'live_id'    => $liveId,
            'client_id'  => $clientId,
            'product_id' => $productId,
            'qty'        => $qty,
        ]);
    } catch (Throwable $e) {
        $this->pdo->rollBack();
        \Engine\Log\LogEngine::boot($this->pdo, $this->ownerId)->error('live.add', 'exception', [
            'live_id'    => $liveId,
            'client_id'  => $clientId,
            'product_id' => $productId,
            'msg'        => $e->getMessage()
        ]);
        throw new RuntimeException('LiveEngine::addProduct failed: ' . $e->getMessage(), 0, $e);
    }
}


    /**
     * Finalizuje wszystkie nieprzeniesione pozycje dla danego live_id:
     * - znajduje/zakłada otwartą grupę zamówienia,
     * - dodaje order_items,
     * - commit rezerwacji,
     * - znaczy live_temp jako przeniesione.
     * Zwraca liczbę przeniesionych pozycji.
     */
    public function finalizeBatch(int $liveId, int $operatorUserId): int
    {
        try {
            $this->pdo->beginTransaction();

            $st = $this->pdo->prepare("
                SELECT *
                  FROM live_temp
                 WHERE owner_id = :oid
                   AND live_id  = :lid
                   AND transferred_at IS NULL
            ");
            $st->execute([':oid' => $this->ownerId, ':lid' => $liveId]);
            $rows = $st->fetchAll();

            if (!$rows) {
                $this->pdo->rollBack();
                return 0;
            }

            $orderEngine = new OrderEngine($this->pdo);
            $migrated = 0;

            foreach ($rows as $row) {
                $clientId  = (int)$row['client_id'];
                $qty       = (float)$row['qty'];
                $unitPrice = (float)($row['price'] ?? 0.0);

                $group = $orderEngine->findOrCreateOpenGroupForLive($this->ownerId, $clientId, $operatorUserId);
                if (!$group) {
                    throw new RuntimeException('Brak grupy zamówienia');
                }

                $orderEngine->addOrderItem([
                    'owner_id'       => $this->ownerId,
                    'order_id'       => (int)$group['order_id'],
                    'order_group_id' => (int)$group['id'],
                    'product_id'     => (int)($row['product_id'] ?? 0),
                    'name'           => (string)$row['name'],
                    'sku'            => (string)($row['sku'] ?? ''),
                    'qty'            => $qty,
                    'unit_price'     => $unitPrice,
                    'vat_rate'       => (float)($row['vat_rate'] ?? 23.0),
                    'source_type'    => OrderItemSourceType::LIVE->value,
                    'source_channel' => 'live',
                ]);

                if (!empty($row['reservation_id'])) {
                    StockReservationEngine::commit($this->pdo, (int)$row['reservation_id']);
                }

                $this->pdo->prepare("
                    UPDATE live_temp
                       SET transferred_at = NOW(),
                           target_order_id = :oid,
                           target_group_id = :gid
                     WHERE id = :id
                ")->execute([
                    ':oid' => (int)$group['order_id'],
                    ':gid' => (int)$group['id'],
                    ':id'  => (int)$row['id']
                ]);

                $migrated++;
            }

            $this->pdo->commit();
            LogEngine::boot($this->pdo, $this->ownerId)->info('live.finalize', 'ok', [
                'live_id' => $liveId, 'migrated' => $migrated
            ]);
            return $migrated;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            LogEngine::boot($this->pdo, $this->ownerId)->error('live.finalize', 'exception', [
                'live_id' => $liveId, 'msg' => $e->getMessage()
            ]);
            throw new RuntimeException('LiveEngine::finalizeBatch failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
