<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$userId  = (int)($_SESSION['user']['id'] ?? 0);

$csrf     = $_POST['csrf'] ?? '';
$orderId  = (int)($_POST['order_id'] ?? 0);
$groupId  = (int)($_POST['group_id'] ?? 0);
$itemId   = (int)($_POST['item_id'] ?? 0);
$qty      = (float)($_POST['qty'] ?? 0);

if (!$ownerId || !$orderId || !$groupId || !$itemId || $qty < 0) {
    http_response_code(400);
    exit('Bad request');
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    exit('Forbidden');
}

try {
    $pdo->beginTransaction();

    $chk = $pdo->prepare("SELECT o.id FROM orders o JOIN order_groups og ON og.order_id=o.id WHERE o.id=:oid AND og.id=:gid AND o.owner_id=:own LIMIT 1");
    $chk->execute(['oid' => $orderId, 'gid' => $groupId, 'own' => $ownerId]);
    if (!$chk->fetch()) throw new RuntimeException('Order/group mismatch');

    $s = $pdo->prepare("SELECT packed_count FROM order_items WHERE id=:iid AND order_group_id=:gid AND owner_id=:own");
    $s->execute(['iid' => $itemId, 'gid' => $groupId, 'own' => $ownerId]);
    $pc = (int)($s->fetchColumn() ?? 0);

    $u = $pdo->prepare("UPDATE order_items SET qty=:q, updated_at=NOW() WHERE id=:iid AND order_group_id=:gid AND owner_id=:own");
    $u->execute(['q' => $qty, 'iid' => $itemId, 'gid' => $groupId, 'own' => $ownerId]);

    $pcNew = $pc;
    if ($pcNew > $qty) $pcNew = (int)floor($qty);
    $u2 = $pdo->prepare("
    UPDATE order_items
       SET packed_count=:pc,
           is_prepared = CASE WHEN :pc >= qty THEN 1 ELSE 0 END,
           packed_at   = CASE WHEN :pc >= qty THEN COALESCE(packed_at, NOW()) ELSE NULL END,
           updated_at  = NOW()
     WHERE id=:iid AND order_group_id=:gid AND owner_id=:own
  ");
    $u2->execute(['pc' => $pcNew, 'iid' => $itemId, 'gid' => $groupId, 'own' => $ownerId]);

    wlog("qty_update item=$itemId qty=$qty packed=$pcNew user=$userId");
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    logg('error', 'orders.item_update_qty', $e->getMessage(), ['order_id' => $orderId, 'group_id' => $groupId, 'item_id' => $itemId]);
}

header('Location: /admin/orders/view.php?id=' . (int)$orderId . '&tab=overview');
exit;
