<?php
declare(strict_types=1);
require_once __DIR__.'/../../../../bootstrap.php';

use Engine\Orders\OrderEngine;

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);

$csrf     = $_POST['csrf'] ?? '';
$orderId  = (int)($_POST['order_id'] ?? 0);
$groupId  = (int)($_POST['order_group_id'] ?? ($_POST['group_id'] ?? 0));
$itemId   = (int)($_POST['item_id'] ?? 0);

if (!$ownerId || !$orderId || !$groupId || !$itemId) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Bad request']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$patch = [];
foreach (['qty','unit_price','vat_rate'] as $k) {
    if (isset($_POST[$k]) && $_POST[$k] !== '') $patch[$k] = (float)$_POST[$k];
}

try {
    $engine = new OrderEngine($pdo);
    $res = $engine->updateOrderItem($ownerId, $orderId, $groupId, $itemId, $patch);
    echo json_encode($res);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
