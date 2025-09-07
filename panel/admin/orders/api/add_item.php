<?php
// admin/orders/api/add_item.php — add by product_id (engine-first)
declare(strict_types=1);

require_once __DIR__ . '/../../../../bootstrap.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/log.php';

use Engine\Orders\OrderEngine;

header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$ownerId  = (int)($_SESSION['user']['owner_id'] ?? 0);
$userId   = (int)($_SESSION['user']['id'] ?? 0);
$orderId  = (int)($_POST['order_id'] ?? 0);
$groupId  = (int)($_POST['group_id'] ?? 0);
$productId= (int)($_POST['product_id'] ?? 0);
$qty      = max(1.0, (float)($_POST['qty'] ?? 1));

if ($ownerId<=0 || $orderId<=0 || $groupId<=0 || $productId<=0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'reason'=>'invalid_params']); exit;
}
$price = isset($_POST['unit_price']) ? (float)$_POST['unit_price'] : null;
// pobierz dane produktu (owner-scope)
$st = $pdo->prepare("
  SELECT p.id, p.name, p.unit_price, p.vat_rate, p.sku
  FROM products p
  WHERE p.id=:pid AND p.owner_id=:oid AND (p.deleted_at IS NULL)
  LIMIT 1
");
$st->execute([':pid'=>$productId, ':oid'=>$ownerId]);
$prod = $st->fetch(\PDO::FETCH_ASSOC);

if (!$prod) {
  echo json_encode(['ok'=>false,'reason'=>'product_not_found']); exit;
}

// ustal client_id z orders (żeby trafił do addOrderItem)
$ord = $pdo->prepare("SELECT client_id FROM orders WHERE id=:oid AND owner_id=:own LIMIT 1");
$ord->execute([':oid'=>$orderId, ':own'=>$ownerId]);
$cli = $ord->fetch(\PDO::FETCH_ASSOC);
if (!$cli) { echo json_encode(['ok'=>false,'reason'=>'order_not_found']); exit; }

$oe = new OrderEngine($pdo);
$res = $oe->addOrderItem([
  'owner_id'   => $ownerId,
  'client_id'  => (int)$cli['client_id'],
  'product_id' => (int)$prod['id'],
  'name'       => (string)$prod['name'],
  'qty'        => (float)$qty,
  'unit_price' => (float)($prod['unit_price'] ?? 0),
  'vat_rate'   => (float)($prod['vat_rate'] ?? 23),
  'sku'        => (string)($prod['sku'] ?? ''),
  'source_type'=> 'manual',
  'channel'    => 'panel',
]);


// ... po pobraniu $prod i $cli:

$unitPrice = $price !== null ? $price : (float)($prod['unit_price'] ?? 0);

$oe = new OrderEngine($pdo);
$res = $oe->addOrderItem([
  'owner_id'   => $ownerId,
  'client_id'  => (int)$cli['client_id'],
  'product_id' => (int)$prod['id'],
  'name'       => (string)$prod['name'],
  'qty'        => (float)$qty,
  'unit_price' => $unitPrice,
  'vat_rate'   => (float)($prod['vat_rate'] ?? 23),
  'sku'        => (string)($prod['sku'] ?? ''),
  'source_type'=> 'manual',
  'channel'    => 'panel',
]);

echo json_encode($res, JSON_UNESCAPED_UNICODE);
