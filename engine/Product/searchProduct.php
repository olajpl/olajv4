<?php
// engine/Product/ProductSearch.php
declare(strict_types=1);

namespace Engine\Product;

use PDO;

class ProductSearch
{
    /**
     * Wyszukiwarka produktów (dla Select2/skanera).
     * @return array<int, array<string,mixed>>
     */
    public static function search(PDO $pdo, int $ownerId, string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($ownerId <= 0 || mb_strlen($q) < 2) {
            return [];
        }
        $limit = max(5, min(50, $limit));

        $esc = fn(string $s): string => str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $s
        );
        $qExact  = $q;
        $qPrefix = $esc($q) . '%';
        $qInfix  = '%' . $esc($q) . '%';

        // Podział na słowa
        $terms = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = array_slice($terms, 0, 5);

        $params = [
            ':owner_id' => $ownerId,
            ':q_exact'  => $qExact,
            ':q_prefix' => $qPrefix,
            ':q_infix'  => $qInfix,
        ];
        $nameAnd = [];
        foreach ($terms as $i => $t) {
            $ph = ":t{$i}";
            $nameAnd[]   = "p.name LIKE {$ph} ESCAPE '\\\\'";
            $params[$ph] = '%' . $esc($t) . '%';
        }

        $blocks = [];
        if ($nameAnd) $blocks[] = '(' . implode(' AND ', $nameAnd) . ')';
        $blocks[] = "p.code LIKE :q_prefix ESCAPE '\\\\'";
        $blocks[] = "COALESCE(p.sku,'') LIKE :q_prefix ESCAPE '\\\\'";
        $blocks[] = "COALESCE(p.ean,'') LIKE :q_prefix ESCAPE '\\\\'";
        $blocks[] = "COALESCE(p.twelve_nc,'') LIKE :q_prefix ESCAPE '\\\\'";
        $blocks[] = "p.name LIKE :q_infix ESCAPE '\\\\'";

        $where = "p.owner_id=:owner_id AND p.deleted_at IS NULL AND (" . implode(' OR ', $blocks) . ")";

        $sql = "
        SELECT
          p.id, p.name, p.code, p.sku, p.ean, p.twelve_nc,
          p.unit_price, p.vat_rate,
          p.stock_available AS stock,
          CASE
            WHEN p.code=:q_exact OR COALESCE(p.ean,'')=:q_exact OR COALESCE(p.sku,'')=:q_exact OR COALESCE(p.twelve_nc,'')=:q_exact THEN 100
            WHEN p.code LIKE :q_prefix OR COALESCE(p.ean,'') LIKE :q_prefix OR COALESCE(p.sku,'') LIKE :q_prefix OR COALESCE(p.twelve_nc,'') LIKE :q_prefix THEN 80
            WHEN p.name LIKE :q_prefix THEN 60
            WHEN p.name LIKE :q_infix  THEN 40
            ELSE 0
          END AS rel_score
        FROM products p
        WHERE {$where}
        ORDER BY rel_score DESC, p.stock_available DESC, p.name ASC
        LIMIT :limit
        ";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(fn($r) => [
            'id'         => (int)$r['id'],
            'name'       => (string)$r['name'],
            'code'       => $r['code'],
            'sku'        => $r['sku'],
            'ean'        => $r['ean'],
            'twelve_nc'  => $r['twelve_nc'],
            'stock'      => (float)$r['stock'],
            'unit_price' => (float)($r['unit_price'] ?? 0),
            'vat_rate'   => (float)($r['vat_rate'] ?? 23.00),
        ], $rows);
    }
}
