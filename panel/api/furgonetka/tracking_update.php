<?php
require_once __DIR__ . '/../../config/config.php';
$pdo = getPDO();

// Autoryzacja
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$expectedToken = 'twój_token_z_system_settings';
if ($auth !== "Bearer $expectedToken") {
  http_response_code(401);
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

// Dane z webhooka
$input = json_decode(file_get_contents('php://input'), true);
$orderId    = (int) ($input['order_id'] ?? 0);
$tracking   = $input['tracking_number'] ?? null;
$labelUrl   = $input['label_url'] ?? null;
$status     = $input['status'] ?? null;

if (!$orderId || !$tracking) {
  http_response_code(400);
  echo json_encode(['error' => 'Brak danych']);
  exit;
}

// Aktualizacja shipping_labels
$stmt = $pdo->prepare("
    UPDATE shipping_labels
    SET tracking_number = :tracking,
        label_url = COALESCE(:label_url, label_url),
        status = COALESCE(:status, status),
        updated_at = NOW()
    WHERE order_id = :order_id
");
$stmt->execute([
  'tracking'   => $tracking,
  'label_url'  => $labelUrl,
  'status'     => $status,
  'order_id'   => $orderId
]);

// Dodaj log statusu
$note = '📦 Aktualizacja przesyłki Furgonetka: ' . $tracking;
if ($status) {
  $note .= ' (Status: ' . $status . ')';
}
$stmt = $pdo->prepare("
    INSERT INTO order_status_log (order_id, status, note, created_at)
    VALUES (:order_id, :status, :note, NOW())
");
$stmt->execute([
  'order_id' => $orderId,
  'status'   => $status ?: 'aktualizacja',
  'note'     => $note
]);

// Opcjonalna zmiana statusu zamówienia
if ($status && strtolower($status) === 'doręczone') {
  $stmt = $pdo->prepare("UPDATE orders SET status = 'zrealizowane' WHERE id = ?");
  $stmt->execute([$orderId]);
}

echo json_encode(['success' => true]);
