<?php
// /admin/products/search.php — autocomplete/skaner produktów (Olaj V4)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';

header('Content-Type: application/json; charset=UTF-8');

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$q        = trim((string)($_GET['q'] ?? ''));
$limit    = max(5, min(50, (int)($_GET['limit'] ?? 20)));

if ($owner_id <= 0 || mb_strlen($q) < 2) {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

// Escaper do LIKE (%, _, \)
function like_escape(string $s): string {
    return str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $s);
}

$q_exact   = $q;
$q_prefix  = like_escape($q) . '%';
$q_infix   = '%' . like_escape($q) . '%';

// Podział na słowa dla AND-owania po nazwie
$terms = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$terms = array_slice($terms, 0, 5);

// WHERE bazowe
$where   = ["p.owner_id = :owner_id", "p.deleted_at IS NULL"];
$params  = [
    ':owner_id'  => $owner_id,
    ':q_exact'   => $q_exact,
    ':q_prefix'  => $q_prefix,
    ':q_infix'   => $q_infix,
];

// name LIKE %term1% AND %term2% …
$nameAnd = [];
foreach ($terms as $i => $t) {
    $ph = ":t{$i}";
    $nameAnd[]   = "p.name LIKE {$ph} ESCAPE '\\\\'";
    $params[$ph] = '%' . like_escape($t) . '%';
}

// Bloki OR: nazwa / code / sku / ean / twelve_nc
$blocks = [];
if ($nameAnd) {
    $blocks[] = '(' . implode(' AND ', $nameAnd) . ')';
}
$blocks[] = "p.code       LIKE :q_prefix ESCAPE '\\\\'";
$blocks[] = "COALESCE(p.sku,'')      LIKE :q_prefix ESCAPE '\\\\'";
$blocks[] = "COALESCE(p.ean,'')      LIKE :q_prefix ESCAPE '\\\\'";
$blocks[] = "COALESCE(p.twelve_nc,'')LIKE :q_prefix ESCAPE '\\\\'";

// full-text-ish fallback po nazwie
$blocks[] = "p.name LIKE :q_infix ESCAPE '\\\\'";

$where[] = '(' . implode(' OR ', $blocks) . ')';

// Trafność — najpierw exacty (skaner), potem prefixy, potem nazwa
// Uwaga: stock w V4 to stock_available (generated)
$sql = "
SELECT
  p.id,
  p.name,
  p.code,
  p.sku,
  p.ean,
  p.twelve_nc,
  p.unit_price,
  p.vat_rate,
  -- Zwracamy jako 'stock' to co front potrzebuje:
  p.stock_available AS stock,

  CASE
    /* 100: exact hit (kod lub EAN lub SKU lub twelve_nc) */
    WHEN p.code       = :q_exact THEN 100
    WHEN COALESCE(p.ean,'')       = :q_exact THEN 100
    WHEN COALESCE(p.sku,'')       = :q_exact THEN 100
    WHEN COALESCE(p.twelve_nc,'') = :q_exact THEN 100

    /* 80: prefix kodów */
    WHEN p.code       LIKE :q_prefix ESCAPE '\\\\' THEN 80
    WHEN COALESCE(p.ean,'')       LIKE :q_prefix ESCAPE '\\\\' THEN 80
    WHEN COALESCE(p.sku,'')       LIKE :q_prefix ESCAPE '\\\\' THEN 80
    WHEN COALESCE(p.twelve_nc,'') LIKE :q_prefix ESCAPE '\\\\' THEN 80

    /* 60: prefix nazwy */
    WHEN p.name LIKE :q_prefix ESCAPE '\\\\' THEN 60

    /* 40: nazwa zawiera */
    WHEN p.name LIKE :q_infix  ESCAPE '\\\\' THEN 40

    ELSE 0
  END AS rel_score
FROM products p
WHERE " . implode(' AND ', $where) . "
ORDER BY rel_score DESC, p.stock_available DESC, p.name ASC
LIMIT :limit
";

// Jeśli mamy twelve_nc_map (opcjonalnie), możemy dorzucić UNION…
// Dla prostoty na razie trzymamy pojedyncze źródło (products).

try {
    $stmt = $pdo->prepare($sql);

    // typy
    $stmt->bindValue(':owner_id', $owner_id, PDO::PARAM_INT);
    $stmt->bindValue(':q_exact',  $q_exact,  PDO::PARAM_STR);
    $stmt->bindValue(':q_prefix', $q_prefix, PDO::PARAM_STR);
    $stmt->bindValue(':q_infix',  $q_infix,  PDO::PARAM_STR);
    foreach ($terms as $i => $t) {
        $stmt->bindValue(":t{$i}", '%' . like_escape($t) . '%', PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Minimalny mapping wyników (spójny z frontendem w orders/view.php)
    // Front zwykle potrzebuje: id, name, code, stock, unit_price, vat_rate
    $out = array_map(static function(array $r): array {
        return [
            'id'         => (int)$r['id'],
            'name'       => (string)$r['name'],
            'code'       => (string)$r['code'],
            'sku'        => $r['sku'],
            'ean'        => $r['ean'],
            'twelve_nc'  => $r['twelve_nc'],
            'stock'      => (float)$r['stock'],       // stock_available
            'unit_price' => $r['unit_price'] !== null ? (float)$r['unit_price'] : null,
            'vat_rate'   => $r['vat_rate']   !== null ? (float)$r['vat_rate']   : null,
        ];
    }, $rows);

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // log i cichy fallback
    wlog('products.search error: ' . $e->getMessage());
    echo json_encode([], JSON_UNESCAPED_UNICODE);
}
