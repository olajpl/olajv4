<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/db.php';
require_once __DIR__.'/../../../includes/log.php';

try {
  $owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
  $order_id = (int)($_REQUEST['order_id'] ?? 0);
  if ($owner_id<=0 || $order_id<=0) { echo json_encode(['ok'=>false,'error'=>'bad_input']); exit; }

  // items_total
  $stmt = $pdo->prepare("
    SELECT COALESCE(SUM(COALESCE(oi.price, oi.unit_price, 0) * COALESCE(oi.quantity, oi.qty, 1)),0)
    FROM order_groups og JOIN order_items oi ON oi.order_group_id=og.id
    WHERE og.order_id=:oid
  ");
  $stmt->execute(['oid'=>$order_id]);
  $items_total = (float)$stmt->fetchColumn();

  // paid
  $stmt = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE
        WHEN status='zaksięgowana' AND transaction_type='wpłata' THEN amount_pln
        WHEN status='zaksięgowana' AND transaction_type='zwrot'  THEN -amount_pln
        ELSE 0 END),0) AS paid_pln
    FROM payment_transactions
    WHERE owner_id=:owner
      AND (order_id=:oid OR order_group_id IN (SELECT id FROM order_groups WHERE order_id=:oid2))
  ");
  $stmt->execute(['owner'=>$owner_id,'oid'=>$order_id,'oid2'=>$order_id]);
  $paid = (float)$stmt->fetchColumn();

  echo json_encode([
    'ok'=>true,
    'order_id'=>$order_id,
    'items_total'=>round($items_total,2),
    'paid'=>round($paid,2),
    'due'=>max(0, round($items_total - $paid, 2))
  ]);
} catch(Throwable $e) {
  logg('error','payments.tx_agg','exception',['msg'=>$e->getMessage()]);
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'exception','message'=>$e->getMessage()]);
}
