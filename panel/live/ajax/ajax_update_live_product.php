<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

$live_temp_id = (int)($_POST['live_temp_id'] ?? 0);
$qty   = (float)($_POST['qty'] ?? 0);
$price = (float)($_POST['price'] ?? 0);
$note  = trim((string)($_POST['note'] ?? ''));
$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);

if ($live_temp_id <= 0 || $qty <= 0 || $price < 0 || $owner_id <= 0) {
  echo json_encode(['success' => false, 'error' => 'Nieprawidłowe dane wejściowe']);
  exit;
}

// walidacja dostępu
$stmt = $pdo->prepare("SELECT id FROM live_temp WHERE id = :id AND owner_id = :oid");
$stmt->execute([':id' => $live_temp_id, ':oid' => $owner_id]);
if (!$stmt->fetchColumn()) {
  echo json_encode(['success' => false, 'error' => 'Nie znaleziono pozycji']);
  exit;
}

// aktualizacja z owner_id w WHERE (dodatkowa ochrona)
$update = $pdo->prepare("UPDATE live_temp 
                         SET qty = :qty, price = :price, note = :note, updated_at = NOW()
                         WHERE id = :id AND owner_id = :oid");
$update->execute([
  ':qty' => $qty,
  ':price' => $price,
  ':note' => $note,
  ':id' => $live_temp_id,
  ':oid' => $owner_id
]);

echo json_encode(['success' => true]);
