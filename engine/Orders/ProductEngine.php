<?php

declare(strict_types=1);

namespace Engine\Orders;

use PDO;

final class ProductEngine
{
    public function __construct(private PDO $pdo) {}

    /**
     * Inteligentne wyszukiwanie produktu po code/sku (owner-safe).
     * Uwaga: unikamy HY093 — NIE używamy dwukrotnie tego samego placeholdera.
     */
    public function findProductSmart(int $ownerId, string $code): array
    {
        $sql = "
            SELECT id, name, sku, unit_price, vat_rate
              FROM products
             WHERE owner_id = :oid
               AND (code = :code1 OR sku = :code2)
             LIMIT 1
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':oid'   => $ownerId,
            ':code1' => $code,
            ':code2' => $code,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? ['ok' => true, 'product' => $row] : ['ok' => false];
    }

    /** Zwraca mapę kolumn/wyrażeń dla stanów magazynowych zgodną z Twoim schematem. */
    public function getStockColumns(): array
    {
        // stock_available = stock_cached - stock_reserved_cached (G/Stored)
        // Zwracamy jawne CAST, żeby dalej mieć spójny typ liczbowy.
        return [
            'stock'          => 'CAST(stock_available AS DECIMAL(10,3))',
            'stock_reserved' => 'CAST(stock_reserved_cached AS DECIMAL(10,3))',
        ];
    }

    /** Produkt po ID (bez weryfikacji ownera). */
    public function getProduct(int $productId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT id, owner_id, code, sku, name,
                   unit_price AS price,
                   vat_rate,
                   stock_cached,
                   stock_reserved_cached,
                   stock_available
              FROM products
             WHERE id = :id
             LIMIT 1
        ");
        $st->execute([':id' => $productId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        // kompatybilność (stare miejsca oczekują 'stock' i 'stock_reserved')
        $row['stock']          = (float)$row['stock_available'];
        $row['stock_reserved'] = (float)$row['stock_reserved_cached'];
        return $row;
    }

    /** Produkt po ID (z weryfikacją ownera). */
    public function getById(int $ownerId, int $productId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT id, owner_id, code, sku, name,
                   unit_price AS price,
                   vat_rate,
                   stock_cached,
                   stock_reserved_cached,
                   stock_available
              FROM products
             WHERE id = :id AND owner_id = :oid
             LIMIT 1
        ");
        $st->execute([':id' => $productId, ':oid' => $ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $row['stock']          = (float)$row['stock_available'];
        $row['stock_reserved'] = (float)$row['stock_reserved_cached'];
        return $row;
    }

    /** Po code (owner-safe). */
    public function getByCode(int $ownerId, string $code): ?array
    {
        $code = $this->normalizeCode($code);
        $st = $this->pdo->prepare("
            SELECT id, owner_id, code, sku, name,
                   unit_price AS price,
                   vat_rate,
                   stock_cached,
                   stock_reserved_cached,
                   stock_available
              FROM products
             WHERE owner_id = :oid AND code = :code
             LIMIT 1
        ");
        $st->execute([':oid' => $ownerId, ':code' => $code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $row['stock']          = (float)$row['stock_available'];
        $row['stock_reserved'] = (float)$row['stock_reserved_cached'];
        return $row;
    }

    // ── lookupy ID ─────────────────────────────────────────────

    public function getProductIdByCode(int $ownerId, string $code): ?int
    {
        $code = $this->normalizeCode($code);
        $st = $this->pdo->prepare("SELECT id FROM products WHERE owner_id = :oid AND code = :code LIMIT 1");
        $st->execute([':oid' => $ownerId, ':code' => $code]);
        $id = $st->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    public function getProductIdBySku(int $ownerId, string $sku): ?int
    {
        $sku = trim($sku);
        if ($sku === '') return null;
        $st = $this->pdo->prepare("SELECT id FROM products WHERE owner_id = :oid AND sku = :sku LIMIT 1");
        $st->execute([':oid' => $ownerId, ':sku' => $sku]);
        $id = $st->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    public function getProductIdByEan(int $ownerId, string $ean): ?int
    {
        $ean = trim($ean);
        if ($ean === '') return null;
        $st = $this->pdo->prepare("SELECT id FROM products WHERE owner_id = :oid AND ean = :ean LIMIT 1");
        $st->execute([':oid' => $ownerId, ':ean' => $ean]);
        $id = $st->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    public function getProductIdBy12nc(int $ownerId, string $code): ?int
    {
        $code = trim($code);
        if ($code === '') return null;

        $st = $this->pdo->prepare("
            SELECT product_id
              FROM twelve_nc_map
             WHERE (owner_id = :oid OR owner_id IS NULL)
               AND (alias = :c OR code = :c)
             ORDER BY (owner_id IS NULL) ASC, id DESC
             LIMIT 1
        ");
        $st->execute([':oid' => $ownerId, ':c' => $code]);
        $id = $st->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    public function getProductIdByName(int $ownerId, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') return null;

        $st = $this->pdo->prepare("
            SELECT id
              FROM products
             WHERE owner_id = :oid
               AND name LIKE :n
             ORDER BY id DESC
             LIMIT 1
        ");
        $st->execute([':oid' => $ownerId, ':n' => '%' . $name . '%']);
        $id = $st->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    // ── resolve + dostępność ──────────────────────────────────

    public function resolveProductId(int $ownerId, ?int $productId = null, ?string $code = null): ?int
    {
        if ($productId) {
            $p = $this->getById($ownerId, $productId);
            return $p ? (int)$p['id'] : null;
        }
        if ($code) {
            return $this->getProductIdByCode($ownerId, $code);
        }
        return null;
    }

    public function checkAvailability(
        int $ownerId,
        ?int $productId,
        ?string $code,
        int $qty
    ): array {
        if (!$productId && !$code) {
            return ['ok' => true, 'reason' => 'custom_product', 'available' => PHP_INT_MAX, 'requested' => $qty];
        }

        $resolvedId = $this->resolveProductId($ownerId, $productId, $code);
        if (!$resolvedId) {
            return ['ok' => false, 'reason' => 'not_found', 'available' => 0, 'requested' => $qty];
        }

        $cols = $this->getStockColumns();
        $st = $this->pdo->prepare("
            SELECT {$cols['stock']} AS stock, {$cols['stock_reserved']} AS stock_reserved
              FROM products
             WHERE id = :id AND owner_id = :oid
             LIMIT 1
        ");
        $st->execute([':id' => $resolvedId, ':oid' => $ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'reason' => 'not_found', 'available' => 0, 'requested' => $qty];
        }

        // Tu 'stock' to już stock_available
        $available = (float)$row['stock'];
        $ok = $available >= $qty;

        return [
            'ok'         => $ok,
            'reason'     => $ok ? 'enough' : 'insufficient',
            'available'  => $available,
            'requested'  => $qty,
            'product_id' => (int)$resolvedId,
        ];
    }

    private function normalizeCode(string $code): string
    {
        return trim($code);
    }
}
