<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$userId  = (int)($_SESSION['user']['id'] ?? 0);

$csrf      = $_POST['csrf'] ?? '';
$orderId   = (int)($_POST['order_id'] ?? 0);
$groupId   = (int)($_POST['group_id'] ?? 0);

$name      = trim((string)($_POST['name'] ?? ''));
$code      = trim((string)($_POST['code'] ?? ''));
$qty       = (float)($_POST['qty'] ?? 0);
$price     = (float)($_POST['unit_price'] ?? 0);
$vat       = (float)($_POST['vat_rate'] ?? 23.00);

if (!$ownerId || !$orderId || !$groupId || $name === '' || $qty <= 0 || $price < 0) {
    http_response_code(400);
    exit('Bad request');
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    exit('Forbidden');
}

try {
    $pdo->beginTransaction();

    // sprawdź uprawnienia i czy checkout nie zamknięty
    $stmt = $pdo->prepare("SELECT o.id, og.checkout_completed
                         FROM orders o
                         JOIN order_groups og ON og.order_id=o.id
                         WHERE o.id=:oid AND og.id=:gid AND o.owner_id=:own LIMIT 1");
    $stmt->execute(['oid' => $orderId, 'gid' => $groupId, 'own' => $ownerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Order/group mismatch');
    if ((int)$row['checkout_completed'] === 1) throw new RuntimeException('Checkout completed');

    $line = round($qty * $price, 2);
    $vatValue = round($line * $vat / (100.0 + $vat), 2);

    $ins = $pdo->prepare("
    INSERT INTO order_items
      (owner_id, order_id, order_group_id, product_id, code, name, qty, unit_price, vat_rate, line_total_gross, vat_value, source_type, source_channel, created_at, updated_at)
    VALUES
      (:own, :oid, :gid, NULL, :code, :name, :qty, :price, :vat, :line, :vat_value, 'admin', 'admin', NOW(), NOW())
  ");
    $ins->execute([
        'own' => $ownerId,
        'oid' => $orderId,
        'gid' => $groupId,
        'code' => $code ?: null,
        'name' => $name,
        'qty' => $qty,
        'price' => $price,
        'vat' => $vat,
        'line' => $line,
        'vat_value' => $vatValue
    ]);

    wlog("item_add gid=$groupId name=$name qty=$qty price=$price user=$userId");
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    logg('error', 'orders.item_add', $e->getMessage(), ['order_id' => $orderId, 'group_id' => $groupId]);
}

$back = '/admin/orders/view.php?id=' . $orderId . '&tab=overview';
header('Location: ' . $back);
exit;
