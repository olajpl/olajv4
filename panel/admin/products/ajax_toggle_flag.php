<?php
// admin/products/ajax_toggle_flag.php — toggle order_items.is_prepared (CSRF + owner check)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';

header('Content-Type: application/json; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) session_start();
$csrf = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$id    = (int)($_POST['order_item_id'] ?? 0);
$set   = (int)($_POST['set'] ?? 0);
$owner = (int)($_SESSION['user']['owner_id'] ?? 0);

try {
    // uprawnienia
    $chk = $pdo->prepare("SELECT oi.id
    FROM order_items oi
    JOIN orders o ON o.id=oi.order_id
    WHERE oi.id=? AND o.owner_id=? LIMIT 1");
    $chk->execute([$id, $owner]);
    if (!$chk->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }

    $st = $pdo->prepare("UPDATE order_items SET is_prepared=? WHERE id=? LIMIT 1");
    $st->execute([$set, $id]);

    logg('info', 'products.kupione', 'toggle_prepared', ['id' => $id, 'set' => $set], ['owner_id' => $owner, 'source' => 'panel']);
    echo json_encode(['ok' => true, 'is_set' => $set]);
} catch (Throwable $e) {
    logg('error', 'products.kupione', 'toggle_prepared_fail', ['message' => $e->getMessage()], ['owner_id' => $owner, 'trace' => $e->getTraceAsString()]);
    echo json_encode(['ok' => false, 'error' => 'db']);
}
