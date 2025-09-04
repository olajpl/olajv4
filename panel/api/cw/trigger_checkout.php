<?php
// panel/api/cw/trigger_checkout.php — CW trigger po checkout_token

require_once __DIR__ . '/../../engine/centralMessaging/CwEngine.php';
header('Content-Type: application/json; charset=utf-8');

$token = $_POST['checkout_token'] ?? '';
if (!$token || !preg_match('/^[a-f0-9\-]{8,}$/i', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid token']);
    exit;
}

try {
    \Olaj\CW\CwEngine::sendAutoReplyCheckoutWithToken($token);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
