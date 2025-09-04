<?php
// admin/products/api/stock_move.php
declare(strict_types=1);
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/db.php';
require_once __DIR__.'/../../../engine/Product/ProductEngine.php';

use Engine\Product\ProductEngine;

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$productId = (int)($input['product_id'] ?? 0);
$delta     = (int)($input['delta'] ?? 0);
$note      = trim((string)($input['note'] ?? 'Szybka korekta'));
$ownerId   = (int)($_SESSION['user']['owner_id'] ?? 0);

if (!$productId || !$ownerId || !$delta) { echo json_encode(['ok'=>false,'error'=>'bad_request']); exit; }

try {
  $eng = new ProductEngine($pdo, $ownerId);
  $eng->moveStock($productId, $delta > 0 ? 'adjust' : 'adjust', $delta, $note);
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
