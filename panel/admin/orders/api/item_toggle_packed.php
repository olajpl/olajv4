<?php
// admin/orders/api/item_toggle_packed.php
declare(strict_types=1);

require_once __DIR__ . '/../../../../bootstrap.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/db.php';
require_once __DIR__ . '/../../../../includes/log.php';
require_once __DIR__ . '/../../../../engine/Orders/OrderEngine.php';

use Engine\Orders\OrderEngine;

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$csrf    = $_POST['csrf'] ?? '';
$itemId  = (int)($_POST['item_id'] ?? 0);
$orderId = (int)($_POST['order_id'] ?? 0);
$groupId = (int)($_POST['group_id'] ?? 0);

if (!$ownerId || !$itemId || !$orderId || !$groupId) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Bad request']); exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$csrf)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}

try {
    $pdo = db(); // Twoja fabryka PDO
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $engine = new OrderEngine($pdo);

    $res = $engine->toggleItemPrepared($ownerId, $orderId, $groupId, $itemId);

    echo json_encode([
        'ok'           => true,
        'is_prepared'  => (int)$res['is_prepared'],
        'packed_count' => (float)$res['packed_count'],
        'qty'          => (float)$res['qty'],
    ]);
} catch (\Throwable $e) {
    logg('error', 'orders.item_toggle_packed', $e->getMessage(), [
        'owner_id'=>$ownerId,'order_id'=>$orderId,'group_id'=>$groupId,'item_id'=>$itemId
    ]);
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
