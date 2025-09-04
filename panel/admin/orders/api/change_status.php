<?php
// admin/orders/api/change_status.php — Olaj.pl V4
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';
require_once __DIR__ . '/../../../engine/Enum/OrderStatus.php';
require_once __DIR__ . '/../../../engine/Orders/OrderEngine.php';

use Engine\Enum\OrderStatus;
use Engine\Orders\OrderEngine;

// ───────────────────────────────────────────────────────────────
// bootstrap + guards
// ───────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

function redirectBack(int $orderId, array $qs = []): void
{
    $base = '/admin/orders/view.php?id=' . (int)$orderId;
    if ($qs) $base .= '&' . http_build_query($qs);
    header('Location: ' . $base);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

// CSRF
$csrf = (string)($_POST['csrf'] ?? '');
if ($csrf === '' || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo 'CSRF validation failed';
    exit;
}

// Sesja / owner
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($ownerId <= 0) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

// Dane wejściowe
$orderId   = (int)($_POST['order_id'] ?? 0);
$newStatus = trim((string)($_POST['status'] ?? ''));

if ($orderId <= 0 || $newStatus === '') {
    http_response_code(400);
    echo 'Missing params';
    exit;
}

// Walidacja statusu z enumem (ENG values)
if (!in_array($newStatus, OrderStatus::ALL, true)) {
    logg('warning', 'admin.orders.change_status', 'Invalid status value', [
        'order_id' => $orderId,
        'owner_id' => $ownerId,
        'status'   => $newStatus,
    ]);
    redirectBack($orderId, ['msg' => 'invalid_status']);
}

// ───────────────────────────────────────────────────────────────
// Pobierz zamówienie i sprawdź właściciela + lock
// ───────────────────────────────────────────────────────────────
try {
    $st = $pdo->prepare("SELECT id, owner_id, order_status, checkout_completed FROM orders WHERE id = ? LIMIT 1");
    $st->execute([$orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    logg('error', 'admin.orders.change_status', 'DB select failed', [
        'order_id' => $orderId,
        'owner_id' => $ownerId,
        'err'      => $e->getMessage(),
    ]);
    redirectBack($orderId, ['msg' => 'db_error']);
}

if (!$order || (int)$order['owner_id'] !== $ownerId) {
    http_response_code(404);
    echo 'Order not found';
    exit;
}

if ((int)($order['checkout_completed'] ?? 0) === 1) {
    redirectBack($orderId, ['msg' => 'locked_checkout']);
}

$oldStatus = (string)$order['order_status'];

// Krótki no-op: nic nie rób gdy bez zmian
if ($oldStatus === $newStatus) {
    redirectBack($orderId, ['msg' => 'no_change']);
}

// ───────────────────────────────────────────────────────────────
// Engine-first: próbujemy przez OrderEngine
// ───────────────────────────────────────────────────────────────
$ok = false;
$engineErr = null;

try {
    if (class_exists(OrderEngine::class) && method_exists(OrderEngine::class, 'changeStatus')) {
        // API: OrderEngine::changeStatus(PDO $pdo, int $orderId, int $ownerId, string $newStatus, array $ctx = []): bool
        $ok = (bool) OrderEngine::changeStatus($pdo, $orderId, $ownerId, $newStatus, [
            'source'     => 'admin_panel',
            'changed_by' => (int)($_SESSION['user']['id'] ?? 0),
            'old_status' => $oldStatus,
        ]);
    } else {
        $engineErr = 'engine_method_missing';
    }
} catch (Throwable $e) {
    $engineErr = $e->getMessage();
}

// ───────────────────────────────────────────────────────────────
// Fallback (awaryjnie) — bez logiki biznesowej, tylko SET + log
// ───────────────────────────────────────────────────────────────
if (!$ok) {
    try {
        $pdo->beginTransaction();
        $u = $pdo->prepare("UPDATE orders SET order_status = :s, updated_at = NOW() WHERE id = :id AND owner_id = :oid");
        $u->execute([
            's'   => $newStatus,
            'id'  => $orderId,
            'oid' => $ownerId,
        ]);

        // Zapisz ślad do logs (centralny logger)
        logg('info', 'orders.status.changed', 'order status changed (fallback)', [
            'order_id'   => $orderId,
            'owner_id'   => $ownerId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'engine_err' => $engineErr,
        ]);

        $pdo->commit();
        $ok = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        logg('error', 'admin.orders.change_status', 'Fallback update failed', [
            'order_id'   => $orderId,
            'owner_id'   => $ownerId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'err'        => $e->getMessage(),
        ]);
        redirectBack($orderId, ['msg' => 'update_failed']);
    }
}

// Sukces → redirect z miłym komunikatem
redirectBack($orderId, [
    'msg'     => 'status_changed',
    'from'    => $oldStatus,
    'to'      => $newStatus,
]);
