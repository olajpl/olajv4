<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);

$csrf     = $_POST['csrf'] ?? '';
$orderId  = (int)($_POST['order_id'] ?? 0);
$groupId  = (int)($_POST['group_id'] ?? 0);
$itemId   = (int)($_POST['item_id'] ?? 0);

if (!$ownerId || !$orderId || !$groupId || !$itemId) {
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

    $del = $pdo->prepare("DELETE FROM order_items WHERE id=:iid AND order_group_id=:gid AND owner_id=:own LIMIT 1");
    $del->execute(['iid' => $itemId, 'gid' => $groupId, 'own' => $ownerId]);

    wlog("item_remove item=$itemId order=$orderId");
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    logg('error', 'orders.item_remove', $e->getMessage(), ['order_id' => $orderId, 'group_id' => $groupId, 'item_id' => $itemId]);
}

header('Location: /admin/orders/view.php?id=' . (int)$orderId . '&tab=overview');
exit;
