<?php
// admin/orders/api/set_shipping_paid_status.php
// Ręczna zmiana statusu opłacenia kosztu wysyłki (orders.shipping_paid_status_*)
// Olaj.pl V4 — owner-safe + CSRF + olaj_v4_logger (LogEngine)
declare(strict_types=1);

require_once __DIR__ . '/../../../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

// ───────────────────────────────────────────────────────────────
// Guard: logger fallback (gdyby include nie zainicjował logg())
// ───────────────────────────────────────────────────────────────
if (!function_exists('logg')) {
    function logg(string $level, string $channel, string $message, array $context = [], array $extra = []): void {
        error_log('[logg-fallback] '.json_encode(compact('level','channel','message','context','extra'), JSON_UNESCAPED_UNICODE));
    }
}

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$userId  = (int)($_SESSION['user']['id'] ?? 0);
$csrf    = (string)($_POST['csrf'] ?? '');
$orderId = (int)($_POST['order_id'] ?? 0);
$value   = (string)($_POST['value'] ?? '');
$ip      = $_SERVER['REMOTE_ADDR'] ?? '';
$ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ref     = $_SERVER['HTTP_REFERER'] ?? '';
$source  = 'panel.api';

// Log: request wejściowy
logg('info', 'orders.shipping_paid', 'request.set_status', [
    'owner_id'=>$ownerId, 'user_id'=>$userId, 'order_id'=>$orderId,
    'value_raw'=>$value, 'ip'=>$ip, 'ua'=>$ua, 'ref'=>$ref, 'source'=>$source
]);

if (!$ownerId || !$orderId) {
    logg('warning', 'orders.shipping_paid', 'reject.bad_request', [
        'owner_id'=>$ownerId, 'order_id'=>$orderId, 'value'=>$value
    ]);
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Bad request']); exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    logg('warning', 'orders.shipping_paid', 'reject.csrf_invalid', [
        'owner_id'=>$ownerId, 'user_id'=>$userId, 'order_id'=>$orderId
    ]);
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'CSRF invalid']); exit;
}

$allowed = ['paid','unpaid'];
if (!in_array($value, $allowed, true)) {
    logg('warning', 'orders.shipping_paid', 'reject.invalid_value', [
        'owner_id'=>$ownerId, 'order_id'=>$orderId, 'value'=>$value, 'allowed'=>$allowed
    ]);
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Invalid value']); exit;
}

try {
    // Owner-scope: czy zamówienie istnieje?
    $stmt = $pdo->prepare("
        SELECT shipping_paid_status_key, shipping_paid_at
        FROM orders
        WHERE id=:oid AND owner_id=:own
        LIMIT 1
    ");
    $stmt->execute([':oid'=>$orderId, ':own'=>$ownerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        logg('warning', 'orders.shipping_paid', 'reject.order_not_found', [
            'owner_id'=>$ownerId, 'order_id'=>$orderId
        ]);
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'Order not found']); exit;
    }

    $prevKey = (string)($row['shipping_paid_status_key'] ?? '');
    $prevAt  = $row['shipping_paid_at'] ?? null;

    // 2) Aktualizacja  ✅ FIX: dwa różne placeholdery
$upd = $pdo->prepare("
    UPDATE orders
    SET shipping_paid_status_set_key = 'group_paid_status',
        shipping_paid_status_key     = :val1,
        shipping_paid_at             = CASE WHEN :val2='paid' THEN NOW() ELSE NULL END,
        updated_at                   = NOW()
    WHERE id=:oid AND owner_id=:own
    LIMIT 1
");
$upd->execute([
    ':val1' => $value,
    ':val2' => $value,
    ':oid'  => $orderId,
    ':own'  => $ownerId
]);


    // Odczyt po aktualizacji
    $sel = $pdo->prepare("
        SELECT shipping_paid_status_key, shipping_paid_at
        FROM orders
        WHERE id=:oid AND owner_id=:own
        LIMIT 1
    ");
    $sel->execute([':oid'=>$orderId, ':own'=>$ownerId]);
    $out = $sel->fetch(PDO::FETCH_ASSOC) ?: [];

    logg('info', 'orders.shipping_paid', 'success.set_status', [
        'owner_id'=>$ownerId, 'user_id'=>$userId, 'order_id'=>$orderId,
        'before'=>['key'=>$prevKey, 'at'=>$prevAt],
        'after' =>['key'=>$out['shipping_paid_status_key'] ?? $value, 'at'=>$out['shipping_paid_at'] ?? null],
        'source'=>$source
    ]);

    echo json_encode([
        'ok'=>true,
        'value'  => $out['shipping_paid_status_key'] ?? $value,
        'paid_at'=> $out['shipping_paid_at'] ?? null
    ]);
} catch (Throwable $e) {
    logg('error', 'orders.shipping_paid', 'error.exception', [
        'owner_id'=>$ownerId, 'user_id'=>$userId, 'order_id'=>$orderId,
        'value'=>$value, 'err'=>$e->getMessage(), 'trace'=>$e->getTraceAsString(), 'source'=>$source
    ]);
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'DB error']);
}
