<?php
// /admin/products/api/search.php — wrapper pod Tom Select
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';
require_once __DIR__ . '/../../../engine/Product/ProductSearch.php';

use Engine\Product\ProductSearch;

header('Content-Type: application/json; charset=UTF-8');

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$q        = trim((string)($_GET['term'] ?? $_GET['q'] ?? ''));
$limit    = (int)($_GET['limit'] ?? 20);

try {
    $res = ProductSearch::search($pdo, $owner_id, $q, $limit);

    // Format dla Tom Select: results = [ {id, name, sku, code, ean, stock, unit_price, ...} ]
    $out = [
        'results' => array_map(fn($r) => [
            'id'         => $r['id'],
            'name'       => $r['name'],
            'sku'        => $r['sku'],
            'code'       => $r['code'],
            'ean'        => $r['ean'],
            'twelve_nc'  => $r['twelve_nc'],
            'stock'      => $r['stock'],
            'unit_price' => $r['unit_price'],
            'vat_rate'   => $r['vat_rate']
        ], $res)
    ];

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    log_exception($e, ['where'=>'products.api_search']);
    echo json_encode(['results'=>[]]);
}
