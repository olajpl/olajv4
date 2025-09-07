<?php
// admin/orders/api/create_or_get.php
declare(strict_types=1);
require_once __DIR__ . '/../../../../bootstrap.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/log.php';

use Engine\Orders\OrderEngine;

header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$ownerId  = (int)($_SESSION['user']['owner_id'] ?? 0);
$userId   = (int)($_SESSION['user']['id'] ?? 0);
$clientId = (int)($_POST['client_id'] ?? 0);

if ($ownerId<=0 || $clientId<=0) { http_response_code(400); echo json_encode(['ok'=>false,'reason'=>'invalid_params']); exit; }

try {
  $oe = new OrderEngine($pdo);
  $o  = $oe->findOrCreateOpenOrderForClient($ownerId, $clientId, 'panel');
  $orderId = (int)($o['id'] ?? 0);
  if ($orderId<=0) throw new \RuntimeException('order_id=0');

  $g = $oe->findOrCreateOpenGroup($orderId);
  $groupId = (int)($g['id'] ?? 0);
  if ($groupId<=0) throw new \RuntimeException('group_id=0');

  echo json_encode(['ok'=>true, 'order_id'=>$orderId, 'group_id'=>$groupId], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
  logg('error','orders.create_or_get','fail',['err'=>$e->getMessage(),'client_id'=>$clientId,'owner_id'=>$ownerId]);
  http_response_code(500);
  echo json_encode(['ok'=>false,'reason'=>'exception','message'=>$e->getMessage()]);
}


