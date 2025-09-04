<?php
// admin/products/api/product_create.php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../engine/Product/ProductEngine.php';

use Engine\Product\ProductEngine;

header('Content-Type: application/json; charset=utf-8');

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
if (!$owner_id) { echo json_encode(['ok'=>false,'error'=>'no_owner']); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $eng = new ProductEngine($pdo, $owner_id);

    // mapowanie pól z formularza
    $data = [
        'name'            => (string)($in['name'] ?? ''),
        'code'            => (string)($in['code'] ?? ''),
        'unit_price'      => (string)($in['unit_price'] ?? '0'),
        'vat_rate'        => (string)($in['vat_rate'] ?? '23'),
        'category_id'     => isset($in['category_id']) ? (int)$in['category_id'] : null,
        'active'          => !empty($in['active']) ? 1 : 0,
        'available_from'  => $in['available_from'] ?? null,
        // Uwaga: wejście z modala to "stock" – silnik bierze "stock_available"
        'stock_available' => (int)($in['stock'] ?? $in['stock_available'] ?? 0),
    ];

    $out = $eng->create($data);
    echo json_encode(['ok'=>true, 'id'=>$out['id']]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
