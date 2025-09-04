<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json');

$token  = $_GET['token'] ?? '';
$status = $_GET['status'] ?? '';
$debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';

$debugData = [];
if ($debug_mode) {
    $debugData['token_received']  = $token;
    $debugData['status_received'] = $status;
    $debugData['step'] = 'start';
}

if (!$token || !$status) {
    if ($debug_mode) $debugData['step'] = 'missing_params';
    echo json_encode(['success' => false, 'message' => 'Brak tokenu lub statusu.', 'debug' => $debug_mode ? $debugData : null]);
    exit;
}

$allowed = ['nowe', 'otwarta_paczka', 'do_wyslania', 'oczekiwanie_na_platnosc', 'wyslane', 'zrealizowane'];
if (!in_array($status, $allowed, true)) {
    if ($debug_mode) $debugData['step'] = 'invalid_status';
    echo json_encode(['success' => false, 'message' => 'Nieprawidłowy status.', 'debug' => $debug_mode ? $debugData : null]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT o.id, og.id AS order_group_id, og.checkout_token
    FROM orders o
    JOIN order_groups og ON og.order_id = o.id
    WHERE og.checkout_token = :token
    LIMIT 1
");
$stmt->execute(['token' => $token]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if ($debug_mode) $debugData['query_result'] = $order;

if (!$order) {
    if ($debug_mode) $debugData['step'] = 'order_not_found';
    echo json_encode(['success' => false, 'message' => 'Nie znaleziono zamówienia.', 'debug' => $debug_mode ? $debugData : null]);
    exit;
}

try {
    $pdo->prepare("UPDATE orders SET status = :status WHERE id = :id")
        ->execute(['status' => $status, 'id' => $order['id']]);

    if ($debug_mode) $debugData['step'] = 'status_updated';
    echo json_encode(['success' => true, 'message' => 'Status został zmieniony.', 'debug' => $debug_mode ? $debugData : null]);
} catch (Exception $e) {
    if ($debug_mode) {
        $debugData['step'] = 'update_error';
        $debugData['error'] = $e->getMessage();
    }
    echo json_encode(['success' => false, 'message' => 'Błąd bazy danych.', 'debug' => $debug_mode ? $debugData : null]);
}
