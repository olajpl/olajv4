<?php

declare(strict_types=1);

namespace Engine\Product;

use PDO;
use Throwable;
use Engine\Log\LogEngine;

final class ProductEngine
{
    private PDO $pdo;
    private int $ownerId;

    public function __construct(PDO $pdo, int $ownerId)
    {
        $this->pdo     = $pdo;
        $this->ownerId = $ownerId;
        // sanity
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        LogEngine::boot($this->pdo, $this->ownerId)->debug('product.engine', 'Boot', [], ['context' => 'product']);
    }

    public static function boot(PDO $pdo, int $ownerId): self
    {
        return new self($pdo, $ownerId);
    }

    /* =========================================================
     * Helpers (schema)
     * ======================================================= */
    private function columnExists(string $table, string $column): bool
    {
        $sql = "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = :t
                   AND COLUMN_NAME  = :c
                 LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':t' => $table, ':c' => $column]);
        return (bool)$st->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
        $st->execute([':t' => $table]);
        return (bool)$st->fetchColumn();
    }

    private function toDecimal($v): float
    {
        $s = str_replace([' ', ','], ['', '.'], (string)$v);
        return round((float)$s, 2);
    }

    private function detectWeightColumn(): ?string
    {
        if ($this->columnExists('products', 'weight_grams')) return 'weight_grams';
        if ($this->columnExists('products', 'weight'))       return 'weight';
        return null;
    }

    /* =========================================================
     * CREATE / UPDATE / DELETE
     * ======================================================= */
    public function create(array $data): array
    {
        LogEngine::boot($this->pdo, $this->ownerId)->debug('product.create', 'Input', [
            'name' => $data['name'] ?? null,
            'code' => $data['code'] ?? null,
        ]);

        try {
            $name = trim((string)($data['name'] ?? ''));
            $code = trim((string)($data['code'] ?? ''));
            if ($name === '') {
                LogEngine::boot($this->pdo, $this->ownerId)->warning('product.create', 'Validation fail: name_required');
                throw new \InvalidArgumentException('name_required');
            }
            if ($code === '') {
                LogEngine::boot($this->pdo, $this->ownerId)->warning('product.create', 'Validation fail: code_required');
                throw new \InvalidArgumentException('code_required');
            }

            $unit_price    = $this->toDecimal($data['unit_price'] ?? 0);
            $vat_rate      = $this->toDecimal($data['vat_rate'] ?? 23);
            $categoryId    = isset($data['category_id']) ? (int)$data['category_id'] : null;
            $stock         = isset($data['stock_available']) ? (int)$data['stock_available'] : 0;
            $active        = !empty($data['active']) ? 1 : 0;
            $availableFrom = isset($data['available_from']) ? (string)$data['available_from'] : null;

            $sql = "INSERT INTO products
                       (owner_id, name, code, unit_price, vat_rate, category_id, stock_cached, active, available_from, created_at, updated_at)
                    VALUES
                       (:owner, :name, :code, :price, :vat, :cat, :stock, :active, :available_from, NOW(), NOW())";
            $this->pdo->prepare($sql)->execute([
                ':owner'          => $this->ownerId,
                ':name'           => $name,
                ':code'           => $code,
                ':price'          => $unit_price,
                ':vat'            => $vat_rate,
                ':cat'            => $categoryId,
                ':stock'          => $stock,
                ':active'         => $active,
                ':available_from' => $availableFrom,
            ]);

            $id = (int)$this->pdo->lastInsertId();

            // Zaksięguj stan początkowy jako adjust (jeśli > 0)
            if ($stock > 0) {
                $this->moveStock($id, 'adjust', $stock, 'Stan początkowy (create)');
            }

            LogEngine::boot($this->pdo, $this->ownerId)->info('product', 'created', [
                'product_id' => $id,
                'name'       => $name,
                'code'       => $code,
                'unit_price' => $unit_price,
                'stock'      => $stock,
            ], ['context' => 'product', 'event' => 'create']);

            return ['id' => $id];
        } catch (Throwable $e) {
            LogEngine::boot($this->pdo, $this->ownerId)->error('product.create', 'Exception', [
                'ex' => $e->getMessage(),
            ], ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    public function update(int $productId, array $data): bool
    {
        LogEngine::boot($this->pdo, $this->ownerId)->debug('product.update', 'Input', [
            'product_id' => $productId,
            'name' => $data['name'] ?? null,
            'code' => $data['code'] ?? null,
        ]);

        try {
            // pobierz bieżący stan
            $q = $this->pdo->prepare("SELECT stock_cached FROM products WHERE id = :id AND owner_id = :oid");
            $q->execute([':id' => $productId, ':oid' => $this->ownerId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                LogEngine::boot($this->pdo, $this->ownerId)->warning('product.update', 'product_not_found', ['product_id' => $productId]);
                throw new \RuntimeException('product_not_found');
            }

            $oldStock = (int)($row['stock_cached'] ?? 0);

            // dane wejściowe
            $name         = trim((string)($data['name'] ?? ''));
            $code         = trim((string)($data['code'] ?? ''));
            if ($name === '') {
                LogEngine::boot($this->pdo, $this->ownerId)->warning('product.update', 'Validation fail: name_required', ['product_id' => $productId]);
                throw new \InvalidArgumentException('name_required');
            }
            if ($code === '') {
                LogEngine::boot($this->pdo, $this->ownerId)->warning('product.update', 'Validation fail: code_required', ['product_id' => $productId]);
                throw new \InvalidArgumentException('code_required');
            }

            $unit_price   = $this->toDecimal($data['unit_price'] ?? 0);
            $vat_rate     = $this->toDecimal($data['vat_rate'] ?? 23);
            $categoryId   = isset($data['category_id']) ? (int)$data['category_id'] : null;
            $active       = !empty($data['active']) ? 1 : 0;
            $availableFrom = isset($data['available_from']) ? (string)$data['available_from'] : null;
            $weight_kg    = isset($data['weight']) ? (float)$data['weight'] : null;
            $desiredStock = isset($data['stock_available']) ? (int)$data['stock_available'] : $oldStock;

            // walidacja rezerwacji (free >= 0)
            $reserved = $this->sumReserved($productId);
            if ($desiredStock < $reserved) {
                LogEngine::boot($this->pdo, $this->ownerId)->warning('product.update', 'stock_lt_reserved', [
                    'product_id' => $productId,
                    'desired' => $desiredStock,
                    'reserved' => $reserved
                ]);
                throw new \RuntimeException('stock_lt_reserved');
            }

            // buduj update
            $fields = [
                'name'       => $name,
                'code'       => $code,
                'unit_price' => $unit_price,
                'vat_rate'   => $vat_rate,
                'active'     => $active,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($categoryId !== null)     $fields['category_id']    = $categoryId;
            if ($availableFrom !== null)  $fields['available_from'] = $availableFrom;

            $weightCol = $this->detectWeightColumn();
            if ($weightCol !== null && $weight_kg !== null) {
                if ($weightCol === 'weight_grams') $fields['weight_grams'] = (int)round($weight_kg * 1000);
                else                                $fields['weight']       = $weight_kg;
            }

            // zbuduj SET
            $setParts = [];
            foreach ($fields as $k => $_) {
                $setParts[] = "$k = :$k";
            }
            $setSql = implode(', ', $setParts);

            $sql = "UPDATE products SET $setSql WHERE id = :id AND owner_id = :oid LIMIT 1";
            $fields['id']  = $productId;
            $fields['oid'] = $this->ownerId;
            $this->pdo->prepare($sql)->execute($fields);

            // korekta stanu (jeśli zmieniono)
            $delta = $desiredStock - $oldStock;
            if ($delta !== 0) {
                $this->moveStock($productId, 'adjust', $delta, 'Korekta (edycja produktu)');
            }

            LogEngine::boot($this->pdo, $this->ownerId)->info('product', 'updated', [
                'product_id'  => $productId,
                'delta_stock' => $delta,
            ], ['context' => 'product', 'event' => 'update']);

            return true;
        } catch (Throwable $e) {
            LogEngine::boot($this->pdo, $this->ownerId)->error('product.update', 'Exception', [
                'product_id' => $productId,
                'ex' => $e->getMessage()
            ], ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    public function delete(int $productId): bool
    {
        try {
            $hasDeletedAt = $this->columnExists('products', 'deleted_at');

            if ($hasDeletedAt) {
                $st = $this->pdo->prepare("UPDATE products SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id AND owner_id = :oid AND deleted_at IS NULL");
                $st->execute([':id' => $productId, ':oid' => $this->ownerId]);
            } else {
                $st = $this->pdo->prepare("DELETE FROM products WHERE id = :id AND owner_id = :oid");
                $st->execute([':id' => $productId, ':oid' => $this->ownerId]);
            }

            LogEngine::boot($this->pdo, $this->ownerId)->info('product', 'deleted', [
                'product_id' => $productId
            ], ['context' => 'product', 'event' => 'delete']);
            return true;
        } catch (Throwable $e) {
            LogEngine::boot($this->pdo, $this->ownerId)->error('product.delete', 'Exception', [
                'product_id' => $productId,
                'ex' => $e->getMessage()
            ], ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    /* =========================================================
     * STOCK
     * ======================================================= */
    public function moveStock(
        int $productId,
        string $movementType, // 'in' | 'out' | 'adjust' | 'return'
        int $quantity,
        ?string $note = null,
        ?int $orderId = null,
        ?int $supplierId = null,
        ?int $createdBy = null
    ): int {
        LogEngine::boot($this->pdo, $this->ownerId)->debug('stock.move', 'Request', [
            'product_id' => $productId,
            'type' => $movementType,
            'qty' => $quantity,
            'note' => $note
        ]);

        if (!in_array($movementType, ['in', 'out', 'adjust', 'return'], true)) {
            LogEngine::boot($this->pdo, $this->ownerId)->warning('stock.move', 'invalid_movement_type', [
                'type' => $movementType
            ]);
            throw new \InvalidArgumentException('invalid_movement_type');
        }
        if ($quantity === 0) {
            LogEngine::boot($this->pdo, $this->ownerId)->debug('stock.move', 'No-op (qty=0)', ['product_id' => $productId]);
            return 0; // nic do zrobienia
        }

        // po tej operacji nie możemy zejść poniżej zarezerwowanych
        $current = $this->getStockNumbers($productId);
        if ($current === null) {
            LogEngine::boot($this->pdo, $this->ownerId)->warning('stock.move', 'product_not_found', ['product_id' => $productId]);
            throw new \RuntimeException('product_not_found');
        }
        $future  = $current['stock_cached'] + $quantity;
        if ($future < $current['stock_reserved']) {
            LogEngine::boot($this->pdo, $this->ownerId)->warning('stock.move', 'stock_would_lt_reserved', [
                'product_id' => $productId,
                'future' => $future,
                'reserved' => $current['stock_reserved']
            ]);
            throw new \RuntimeException('stock_would_lt_reserved');
        }

        $this->pdo->beginTransaction();
        try {
            // aktualizacja products.stock_cached
            $this->pdo->prepare("
                UPDATE products
                   SET stock_cached = stock_cached + :d,
                       updated_at   = NOW()
                 WHERE id = :pid AND owner_id = :oid
            ")->execute([
                ':d'   => $quantity,
                ':pid' => $productId,
                ':oid' => $this->ownerId
            ]);

            // wpis w stock_movements (qty)
            $this->pdo->prepare("
                INSERT INTO stock_movements
                    (product_id, owner_id, warehouse_id, qty, movement_type, source_type, source_id, note, flags, metadata, created_at, created_by)
                VALUES
                    (:pid, :oid, NULL, :qty, :type, 'manual', :sid, :note, '', NULL, NOW(), :cby)
            ")->execute([
                ':pid' => $productId,
                ':oid' => $this->ownerId,
                ':qty' => $quantity,
                ':type' => $movementType,
                ':sid' => $orderId,      // opcjonalnie
                ':note' => $note,
                ':cby' => $createdBy,
            ]);

            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();

            LogEngine::boot($this->pdo, $this->ownerId)->info('stock', 'move', [
                'product_id'   => $productId,
                'movement_id'  => $id,
                'qty'          => $quantity,
                'type'         => $movementType,
            ], ['context' => 'stock', 'event' => 'move']);

            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            LogEngine::boot($this->pdo, $this->ownerId)->error('stock.move', 'Exception', [
                'product_id' => $productId,
                'ex' => $e->getMessage()
            ], ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    public function adjustStock(int $productId, int $deltaQty, string $note = 'Korekta ręczna'): int
    {
        return $this->moveStock($productId, 'adjust', $deltaQty, $note);
    }

    public function getStockStatus(int $productId): ?array
    {
        $nums = $this->getStockNumbers($productId);
        if ($nums === null) return null;

        $out = [
            'stock_cached'   => $nums['stock_cached'],
            'stock_reserved' => $nums['stock_reserved'],
            'stock_free'     => max(0, $nums['stock_cached'] - $nums['stock_reserved']),
        ];
        LogEngine::boot($this->pdo, $this->ownerId)->debug('stock.status', 'Computed', ['product_id' => $productId] + $out);
        return $out;
    }

    private function getStockNumbers(int $productId): ?array
    {
        // stock_cached
        $q = $this->pdo->prepare("SELECT stock_cached FROM products WHERE id = :pid AND owner_id = :oid");
        $q->execute([':pid' => $productId, ':oid' => $this->ownerId]);
        $stockCached = $q->fetchColumn();
        if ($stockCached === false) return null;

        // rezerwacje
        $reserved = $this->sumReserved($productId);

        return [
            'stock_cached'   => (int)$stockCached,
            'stock_reserved' => (int)$reserved
        ];
    }

    private function sumReserved(int $productId): int
    {
        if (!$this->tableExists('stock_reservations')) return 0;

        $st = $this->pdo->prepare("
            SELECT COALESCE(SUM(qty),0)
              FROM stock_reservations
             WHERE product_id = :pid
               AND owner_id   = :oid
               AND status     = 'reserved'
        ");
        $st->execute([':pid' => $productId, ':oid' => $this->ownerId]);
        $sum = (int)$st->fetchColumn();
        LogEngine::boot($this->pdo, $this->ownerId)->debug('stock.reserved', 'Sum', [
            'product_id' => $productId,
            'reserved' => $sum
        ]);
        return $sum;
    }

    public function recalculateStockFromMovements(int $productId): int
    {
        LogEngine::boot($this->pdo, $this->ownerId)->info('stock.recalc', 'start', ['product_id' => $productId]);

        $sql = "
            SELECT COALESCE(SUM(
                CASE
                    WHEN movement_type IN ('in','return','adjust') THEN qty
                    WHEN movement_type = 'out'                    THEN -qty
                    ELSE 0
                END
            ),0) AS total
            FROM stock_movements
            WHERE product_id = :pid AND owner_id = :oid
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':pid' => $productId, ':oid' => $this->ownerId]);
        $total = (float)$st->fetchColumn();

        $this->pdo->prepare("
            UPDATE products
               SET stock_cached = :t, updated_at = NOW()
             WHERE id = :pid AND owner_id = :oid
        ")->execute([':t' => $total, ':pid' => $productId, ':oid' => $this->ownerId]);

        LogEngine::boot($this->pdo, $this->ownerId)->info('stock.recalc', 'done', [
            'product_id' => $productId,
            'stock_cached' => $total
        ]);
        return (int)$total;
    }

    public function recalculateAllStocks(): void
    {
        LogEngine::boot($this->pdo, $this->ownerId)->info('stock.recalc_all', 'start');

        $sql = "
            SELECT product_id,
                   COALESCE(SUM(
                       CASE
                           WHEN movement_type IN ('in','return','adjust') THEN qty
                           WHEN movement_type = 'out'                    THEN -qty
                           ELSE 0
                       END
                   ),0) AS total
              FROM stock_movements
             WHERE owner_id = :oid
             GROUP BY product_id
        ";
        $q = $this->pdo->prepare($sql);
        $q->execute([':oid' => $this->ownerId]);

        $upd = $this->pdo->prepare("
            UPDATE products
               SET stock_cached = :t, updated_at = NOW()
             WHERE id = :pid AND owner_id = :oid
        ");

        $cnt = 0;
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $upd->execute([
                ':t'   => (float)$row['total'],
                ':pid' => (int)$row['product_id'],
                ':oid' => $this->ownerId
            ]);
            $cnt++;
        }

        LogEngine::boot($this->pdo, $this->ownerId)->info('stock.recalc_all', 'done', ['affected' => $cnt]);
    }

    /* =========================================================
     * SEARCH / RETRIEVE
     * ======================================================= */
    public function getById(int $productId, bool $includeDeleted = false): ?array
    {
        $sql = "SELECT * FROM products WHERE id = :id AND owner_id = :oid";
        if ($this->columnExists('products', 'deleted_at') && !$includeDeleted) {
            $sql .= " AND deleted_at IS NULL";
        }
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $productId, ':oid' => $this->ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        LogEngine::boot($this->pdo, $this->ownerId)->debug('product.getById', $row ? 'found' : 'not_found', ['product_id' => $productId]);
        return $row ?: null;
    }

    public function getWithTags(int $productId): ?array
    {
        $p = $this->getById($productId, false);
        if (!$p) return null;

        if ($this->tableExists('product_tag_links') && $this->tableExists('product_tags')) {
            $st = $this->pdo->prepare("
                SELECT t.id, t.name, t.color
                  FROM product_tag_links l
                  JOIN product_tags t ON t.id = l.tag_id
                 WHERE l.product_id = :pid
                   AND t.owner_id   = :oid
                 ORDER BY t.name ASC
            ");
            $st->execute([':pid' => $productId, ':oid' => $this->ownerId]);
            $p['tags'] = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $p['tags'] = [];
        }

        LogEngine::boot($this->pdo, $this->ownerId)->debug('product.getWithTags', 'ok', [
            'product_id' => $productId,
            'tags_cnt' => count($p['tags'])
        ]);
        return $p;
    }

    public function findByCode(string $code): ?array
    {
        $st = $this->pdo->prepare(
            "
            SELECT * FROM products
             WHERE code = :c AND owner_id = :oid" .
                ($this->columnExists('products', 'deleted_at') ? " AND deleted_at IS NULL" : "") .
                " LIMIT 1"
        );
        $st->execute([':c' => $code, ':oid' => $this->ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        LogEngine::boot($this->pdo, $this->ownerId)->debug('product.findByCode', $row ? 'found' : 'not_found', ['code' => $code]);
        return $row ?: null;
    }

    public function getAllActive(): array
    {
        $sql = "SELECT * FROM products WHERE owner_id = :oid AND active = 1";
        if ($this->columnExists('products', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
        $sql .= " ORDER BY name ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute([':oid' => $this->ownerId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        LogEngine::boot($this->pdo, $this->ownerId)->debug('product.getAllActive', 'ok', ['count' => count($rows)]);
        return $rows;
    }

    public function getByCodePartialMatch(string $query, int $limit = 10): array
    {
        $q = '%' . trim($query) . '%';
        $limit = max(1, min(100, $limit));
        $sql = "
            SELECT * FROM products
             WHERE owner_id = :oid
               AND (code LIKE :q OR name LIKE :q OR twelve_nc LIKE :q)";
        if ($this->columnExists('products', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
        $sql .= " ORDER BY name ASC LIMIT " . (int)$limit;

        $st = $this->pdo->prepare($sql);
        $st->execute([':oid' => $this->ownerId, ':q' => $q]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        LogEngine::boot($this->pdo, $this->ownerId)->debug('product.search', 'ok', [
            'query' => $query,
            'limit' => $limit,
            'count' => count($rows)
        ]);
        return $rows;
    }

    /* =========================================================
     * TAGS / IMAGES / MISC
     * ======================================================= */
    public function setTags(int $productId, array $tagIds): void
    {
        LogEngine::boot($this->pdo, $this->ownerId)->debug('product.tags', 'set', [
            'product_id' => $productId,
            'tag_ids_count' => count($tagIds)
        ]);

        if (!$this->tableExists('product_tag_links')) return;

        $this->pdo->beginTransaction();
        try {
            // czyść tylko dla danego ownera
            $this->pdo->prepare("DELETE FROM product_tag_links WHERE product_id = :pid AND owner_id = :oid")
                ->execute([':pid' => $productId, ':oid' => $this->ownerId]);

            if ($tagIds) {
                // walidacja tagów po ownerze (jeśli mamy product_tags)
                $valid = array_map('intval', $tagIds);
                if ($this->tableExists('product_tags')) {
                    $place = implode(',', array_fill(0, count($valid), '?'));
                    $sql   = "SELECT id FROM product_tags WHERE owner_id = ? AND id IN ($place)";
                    $st    = $this->pdo->prepare($sql);
                    $st->bindValue(1, $this->ownerId, PDO::PARAM_INT);
                    $i = 2;
                    foreach ($valid as $tid) {
                        $st->bindValue($i++, $tid, PDO::PARAM_INT);
                    }
                    $st->execute();
                    $valid = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                }

                if ($valid) {
                    $ins = $this->pdo->prepare("
                    INSERT IGNORE INTO product_tag_links (product_id, tag_id, owner_id)
                    VALUES (:pid, :tid, :oid)
                ");
                    foreach (array_unique($valid) as $tid) {
                        $ins->execute([':pid' => $productId, ':tid' => $tid, ':oid' => $this->ownerId]);
                    }
                }
            }

            $this->pdo->commit();
            LogEngine::boot($this->pdo, $this->ownerId)->info('product.tags', 'updated', [
                'product_id' => $productId
            ]);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            LogEngine::boot($this->pdo, $this->ownerId)->error('product.tags', 'Exception', [
                'product_id' => $productId,
                'ex' => $e->getMessage()
            ], ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }


    public function setMainImage(int $productId, string $imagePath): void
    {
        if ($productId <= 0 || $imagePath === '') {
            throw new InvalidArgumentException("Invalid product or image path");
        }

        $this->requireOwner();

        $pdo = $this->pdo;

        $pdo->beginTransaction();
        try {
            // Wyzeruj is_main
            $stmt = $pdo->prepare("
            UPDATE product_images
            SET is_main = 0
            WHERE owner_id = :owner_id AND product_id = :product_id
        ");
            $stmt->execute([
                'owner_id' => $this->owner_id,
                'product_id' => $productId
            ]);

            // Ustaw is_main = 1 tam gdzie ścieżka się zgadza
            $stmt = $pdo->prepare("
            UPDATE product_images
            SET is_main = 1
            WHERE owner_id = :owner_id AND product_id = :product_id AND image_path = :path
        ");
            $stmt->execute([
                'owner_id' => $this->owner_id,
                'product_id' => $productId,
                'path' => $imagePath
            ]);

            // Jeśli nie zaktualizowano nic — znaczy że trzeba wstawić nowy rekord
            if ($stmt->rowCount() === 0) {
                $stmt = $pdo->prepare("
                INSERT INTO product_images (owner_id, product_id, image_path, is_main)
                VALUES (:owner_id, :product_id, :path, 1)
            ");
                $stmt->execute([
                    'owner_id' => $this->owner_id,
                    'product_id' => $productId,
                    'path' => $imagePath
                ]);
            }

            $pdo->commit();

            wlog("setMainImage() → zapisano {$imagePath} jako główne dla produktu {$productId}");
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Failed to set main image: " . $e->getMessage(), 0, $e);
        }
    }


    public function setGalleryImages(int $productId, array $pathsOrUrls): void
    {
        if (!$this->tableExists('product_images')) return;

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("
            DELETE FROM product_images
             WHERE product_id = :pid AND owner_id = :oid
        ")->execute([':pid' => $productId, ':oid' => $this->ownerId]);

            $ins = $this->pdo->prepare("
            INSERT INTO product_images (owner_id, product_id, image_path, sort_order, uploaded_at)
            VALUES (:oid, :pid, :p, :s, NOW())
        ");

            $i = 1;
            foreach ($pathsOrUrls as $p) {
                $p = trim((string)$p);
                if ($p === '') continue;
                $ins->execute([':oid' => $this->ownerId, ':pid' => $productId, ':p' => $p, ':s' => $i++]);
            }

            $this->pdo->commit();
            LogEngine::boot($this->pdo, $this->ownerId)->info('product.gallery', 'updated', [
                'product_id' => $productId,
                'count' => $i - 1
            ]);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            LogEngine::boot($this->pdo, $this->ownerId)->error('product.gallery', 'Exception', [
                'product_id' => $productId,
                'ex' => $e->getMessage()
            ], ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }


    public function generateAiDescription(int $productId): void
    {
        if (!$this->tableExists('product_descriptions')) return;

        $p = $this->getById($productId);
        if (!$p) {
            LogEngine::boot($this->pdo, $this->ownerId)->warning('product.ai', 'not_found', ['product_id' => $productId]);
            throw new \RuntimeException('not_found');
        }

        $text = "[AI wygenerowany opis dla {$p['name']}]";

        $this->pdo->prepare("
            INSERT INTO product_descriptions (product_id, owner_id, generated_at, content)
            VALUES (:pid, :oid, NOW(), :txt)
            ON DUPLICATE KEY UPDATE content = VALUES(content), generated_at = NOW()
        ")->execute([':pid' => $productId, ':oid' => $this->ownerId, ':txt' => $text]);

        LogEngine::boot($this->pdo, $this->ownerId)->info('product.ai', 'description_generated', ['product_id' => $productId]);
    }

    /* =========================================================
     * SALES (order_items)
     * ======================================================= */
    public function countSold(int $productId): int
    {
        if (!$this->tableExists('order_items')) return 0;

        $st = $this->pdo->prepare("
            SELECT COALESCE(SUM(qty),0)
              FROM order_items
             WHERE owner_id  = :oid
               AND product_id = :pid
        ");
        $st->execute([':oid' => $this->ownerId, ':pid' => $productId]);
        $sum = (int)$st->fetchColumn();
        LogEngine::boot($this->pdo, $this->ownerId)->debug('product.sales.countSold', 'ok', [
            'product_id' => $productId,
            'qty_sum' => $sum
        ]);
        return $sum;
    }

    public function salesSummary(int $productId, string $fromIso): array
    {
        if (!$this->tableExists('order_items')) return ['rows_cnt' => 0, 'qty_sum' => 0, 'revenue' => 0.0];

        $dateCol = $this->firstExistingDateColumn('orders', ['created_at', 'placed_at', 'paid_at', 'updated_at']);

        if ($dateCol !== null && $this->tableExists('orders')) {
            $sql = "
                SELECT COUNT(*) AS rows_cnt,
                       COALESCE(SUM(oi.qty),0) AS qty_sum,
                       COALESCE(SUM(oi.qty * oi.unit_price),0) AS revenue
                  FROM order_items oi
                  JOIN orders o ON o.id = oi.order_id
                 WHERE oi.owner_id = :oid
                   AND oi.product_id = :pid
                   AND o.`$dateCol` >= :from";
            $st = $this->pdo->prepare($sql);
            $st->execute([':oid' => $this->ownerId, ':pid' => $productId, ':from' => $fromIso]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $out = [
                'rows_cnt' => (int)($row['rows_cnt'] ?? 0),
                'qty_sum'  => (float)($row['qty_sum'] ?? 0),
                'revenue'  => (float)($row['revenue'] ?? 0),
            ];
            LogEngine::boot($this->pdo, $this->ownerId)->debug('product.sales.summary', 'ok', [
                'product_id' => $productId,
                'from' => $fromIso
            ] + $out);
            return $out;
        }

        // fallback: bez filtrowania po datach
        $st = $this->pdo->prepare("
            SELECT COUNT(*) AS rows_cnt,
                   COALESCE(SUM(qty),0) AS qty_sum,
                   COALESCE(SUM(qty * unit_price),0) AS revenue
              FROM order_items
             WHERE owner_id = :oid
               AND product_id = :pid
        ");
        $st->execute([':oid' => $this->ownerId, ':pid' => $productId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['rows_cnt' => 0, 'qty_sum' => 0, 'revenue' => 0];
        $out = [
            'rows_cnt' => (int)$row['rows_cnt'],
            'qty_sum'  => (float)$row['qty_sum'],
            'revenue'  => (float)$row['revenue'],
        ];
        LogEngine::boot($this->pdo, $this->ownerId)->debug('product.sales.summary', 'fallback', [
            'product_id' => $productId
        ] + $out);
        return $out;
    }

    public function lastSales(int $productId, int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        $dateCol = $this->firstExistingDateColumn('orders', ['created_at', 'placed_at', 'paid_at', 'updated_at']);

        if ($this->tableExists('order_items') && $this->tableExists('orders') && $dateCol !== null) {
            $sql = "
                SELECT oi.id, oi.qty AS quantity, oi.unit_price, o.id AS order_id, o.`$dateCol` AS o_dt
                  FROM order_items oi
                  JOIN orders o ON o.id = oi.order_id
                 WHERE oi.owner_id = :oid
                   AND oi.product_id = :pid
                 ORDER BY o.`$dateCol` DESC
                 LIMIT $limit";
            $st = $this->pdo->prepare($sql);
            $st->execute([':oid' => $this->ownerId, ':pid' => $productId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            LogEngine::boot($this->pdo, $this->ownerId)->debug('product.sales.last', 'ok', [
                'product_id' => $productId,
                'count' => count($rows)
            ]);
            return $rows;
        }

        // fallback bez daty
        if ($this->tableExists('order_items')) {
            $sql = "
                SELECT oi.id, oi.qty AS quantity, oi.unit_price, oi.order_id, NULL AS o_dt
                  FROM order_items oi
                 WHERE oi.owner_id = :oid
                   AND oi.product_id = :pid
                 ORDER BY oi.id DESC
                 LIMIT $limit";
            $st = $this->pdo->prepare($sql);
            $st->execute([':oid' => $this->ownerId, ':pid' => $productId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            LogEngine::boot($this->pdo, $this->ownerId)->debug('product.sales.last', 'fallback', [
                'product_id' => $productId,
                'count' => count($rows)
            ]);
            return $rows;
        }

        return [];
    }

    private function firstExistingDateColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if ($this->columnExists($table, $c)) return $c;
        }
        return null;
    }
    /* =========================================================
 * LIST / COUNT (for admin/products/index.php)
 * ======================================================= */
    public function listProducts(array $params): array
    {
        $ownerId      = $params['owner_id'] ?? $this->ownerId;
        $limit        = max(1, min(100, (int)($params['limit'] ?? 100)));
        $page         = max(1, (int)($params['page'] ?? 1));
        $offset       = ($page - 1) * $limit;

        $categoryId   = isset($params['category_id'])  ? (int)$params['category_id']   : null;
        $tagId        = isset($params['tag_id'])       ? (int)$params['tag_id']        : null;
        $active       = isset($params['active'])       ? (int)$params['active']        : null;
        $availability = isset($params['availability']) ? (string)$params['availability'] : null; // 'in_stock'|'out_of_stock'
        $q            = isset($params['q'])            ? trim((string)$params['q'])     : null;

        $withTags     = !empty($params['with_tags']);
        $withImages   = !empty($params['with_images']);
        $withReserved = !empty($params['with_reserved']);

        // ——— wyczucie kolumn (odporne na różne schematy)
        $hasUnitPrice   = $this->columnExists('products', 'unit_price');
        $hasPrice       = $this->columnExists('products', 'price');
        $priceExpr      = $hasUnitPrice ? 'p.unit_price' : ($hasPrice ? 'p.price' : 'NULL');

        $hasStockAvail  = $this->columnExists('products', 'stock_available');
        $hasStockCached = $this->columnExists('products', 'stock_cached');
        $hasStock       = $this->columnExists('products', 'stock');
        $stockExpr      = $hasStockAvail ? 'p.stock_available' : ($hasStockCached ? 'p.stock_cached' : ($hasStock ? 'p.stock' : 'NULL'));

        $vatExpr        = $this->columnExists('products', 'vat_rate') ? 'p.vat_rate' : 'NULL';
        $codeExpr       = $this->columnExists('products', 'code')     ? 'p.code'     : 'NULL';
        $twelveExpr     = $this->columnExists('products', 'twelve_nc') ? 'p.twelve_nc' : 'NULL';
        $activeExpr     = $this->columnExists('products', 'active')   ? 'p.active'   : '1';

        $paramsBind = [':oid' => $ownerId];
        $filters = ["p.owner_id = :oid"];
        if ($active !== null && $this->columnExists('products', 'active')) {
            $filters[] = "p.active = :active";
            $paramsBind[':active'] = $active;
        }
        if ($categoryId !== null && $this->columnExists('products', 'category_id')) {
            $filters[] = "p.category_id = :cat";
            $paramsBind[':cat'] = $categoryId;
        }
        if ($q !== null && $q !== '') {
            $filters[] = "(p.name LIKE :q OR $codeExpr LIKE :q)";
            $paramsBind[':q'] = "%{$q}%";
        }
        if ($this->tableExists('product_tag_links') && $tagId !== null) {
            $filters[] = "EXISTS (SELECT 1 FROM product_tag_links l WHERE l.product_id=p.id AND l.tag_id=:tid)";
            $paramsBind[':tid'] = $tagId;
        }

        // ——— JOIN rezerwacji (opcjonalny)
        $joinRes = '';
        $selectRes = '0 AS reserved_quantity';
        if ($withReserved && $this->tableExists('stock_reservations')) {
            $joinRes = "
            LEFT JOIN (
                SELECT product_id, owner_id, COALESCE(SUM(qty),0) AS reserved_qty
                  FROM stock_reservations
                 WHERE status='reserved'
                 GROUP BY product_id, owner_id
            ) sr ON sr.product_id=p.id AND sr.owner_id=p.owner_id
        ";
            $selectRes = "COALESCE(sr.reserved_qty,0) AS reserved_quantity";
        }

        // ——— JOIN obrazka głównego (owner-safe, bez window functions; działa na MySQL 5.7+)
        $joinImg = '';
        $selectImg = "CONCAT('/uploads/', img.is_main) AS is_main";
        if ($withImages) {
            $hasPI = $this->tableExists('product_images');
            $piHasPath = $hasPI && $this->columnExists('product_images', 'image_path');
            if ($piHasPath) {
                // wybierz preferowany rekord per product_id: (is_main=1 preferowane) a potem sort_order, potem id
                $joinImg = "
                LEFT JOIN (
                    SELECT pi1.product_id,
                           pi1.image_path AS is_main
                      FROM product_images pi1
                      JOIN (
                            SELECT product_id,
                                   MIN(
                                       CONCAT(
                                          LPAD(CASE WHEN is_main=1 THEN 0 ELSE 1 END,1,'0'),
                                          LPAD(COALESCE(sort_order,9999),6,'0'),
                                          LPAD(id,10,'0')
                                       )
                                   ) AS pick_key
                              FROM product_images
                             WHERE owner_id = :oid_img
                             GROUP BY product_id
                      ) pick ON pick.product_id = pi1.product_id
                      JOIN product_images pi2
                        ON pi2.product_id = pi1.product_id
                       AND CONCAT(
                              LPAD(CASE WHEN pi2.is_main=1 THEN 0 ELSE 1 END,1,'0'),
                              LPAD(COALESCE(pi2.sort_order,9999),6,'0'),
                              LPAD(pi2.id,10,'0')
                           ) = pick.pick_key
                     AND pi2.id = pi1.id
                ) img ON img.product_id = p.id
            ";
                $paramsBind[':oid_img'] = $ownerId;
                $selectImg = "img.is_main";
            } elseif ($this->columnExists('products', 'is_main')) {
                $selectImg = "p.is_main";
            } elseif ($this->columnExists('products', 'image_url')) {
                $selectImg = "p.image_url";
            }
        }

        // ——— Budowa WHERE
        $where = implode(' AND ', $filters);

        // ——— Dostępność (na podstawie wyliczonego stockExpr i rezerwacji)
        $availSQL = '';
        if ($availability && $stockExpr !== 'NULL') {
            if ($availability === 'in_stock') {
                $availSQL = $withReserved ? " AND (($stockExpr - COALESCE(sr.reserved_qty,0)) > 0)" : " AND ($stockExpr > 0)";
            } elseif ($availability === 'out_of_stock') {
                $availSQL = $withReserved ? " AND (($stockExpr - COALESCE(sr.reserved_qty,0)) <= 0)" : " AND ($stockExpr <= 0)";
            }
        }

        // ——— COUNT (total)
        $sqlBase = "FROM products p $joinRes $joinImg WHERE $where";
        $sqlCount = "SELECT COUNT(*) $sqlBase $availSQL";
        $stCnt = $this->pdo->prepare($sqlCount);
        $stCnt->execute($paramsBind);
        $total = (int)$stCnt->fetchColumn();

        // ——— LIST
        $sqlList = "
        SELECT
            p.id, p.name,
            $codeExpr   AS code,
            $priceExpr  AS unit_price,
            $stockExpr  AS stock_available,
            $vatExpr    AS vat_rate,
            $twelveExpr AS twelve_nc,
            $activeExpr AS active,
            $selectRes,
            $selectImg
        $sqlBase
        $availSQL
        ORDER BY p.name ASC
        LIMIT :lim OFFSET :off
    ";
        $paramsList = $paramsBind + [':lim' => $limit, ':off' => $offset];
        $st = $this->pdo->prepare($sqlList);
        $st->execute($paramsList);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // ——— Tagi (opcjonalnie)
        if ($withTags && $items) {
            $ids = array_map(fn($r) => (int)$r['id'], $items);
            if ($this->tableExists('product_tag_links') && $this->tableExists('product_tags')) {
                $place = implode(',', array_fill(0, count($ids), '?'));
                $sqlT = "
                SELECT l.product_id, t.name, t.color
                  FROM product_tag_links l
                  JOIN product_tags t ON t.id=l.tag_id AND t.owner_id=?
                 WHERE l.product_id IN ($place)
            ";
                $qT = $this->pdo->prepare($sqlT);
                $qT->bindValue(1, $ownerId, PDO::PARAM_INT);
                $i = 2;
                foreach ($ids as $id) $qT->bindValue($i++, $id, PDO::PARAM_INT);
                $qT->execute();

                $byPid = [];
                while ($r = $qT->fetch(PDO::FETCH_ASSOC)) {
                    $byPid[(int)$r['product_id']][] = ['name' => $r['name'], 'color' => $r['color']];
                }
                foreach ($items as &$it) {
                    $it['tags'] = $byPid[(int)$it['id']] ?? [];
                }
            } else {
                foreach ($items as &$it) $it['tags'] = [];
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'page'  => $page,
        ];
    }

    public function countProducts(array $params): int
    {
        // minimalny wrapper – używa listProducts() do policzenia, ale bez kosztownych JOINów
        $params = $params + ['with_images' => false, 'with_reserved' => false, 'with_tags' => false, 'limit' => 1, 'page' => 1];
        $res = $this->listProducts($params);
        return (int)($res['total'] ?? 0);
    }
}
