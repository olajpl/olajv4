<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

$live_temp_id = (int)($_POST['live_temp_id'] ?? 0);
$qty = (int)($_POST['qty'] ?? 0);
$price = (float)($_POST['price'] ?? 0);
$note = trim($_POST['note'] ?? '');
$owner_id = $_SESSION['user']['owner_id'] ?? 0;

if ($live_temp_id <= 0 || $qty <= 0 || $price < 0) {
  echo json_encode(['error' => 'Nieprawidłowe dane wejściowe']);
  exit;
}

// walidacja dostępu
$stmt = $pdo->prepare("SELECT * FROM live_temp WHERE id = ? AND owner_id = ?");
$stmt->execute([$live_temp_id, $owner_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  echo json_encode(['error' => 'Nie znaleziono pozycji']);
  exit;
}

// aktualizacja
$update = $pdo->prepare("UPDATE live_temp SET qty = ?, price = ?, note = ? WHERE id = ?");
$update->execute([$qty, $price, $note, $live_temp_id]);

echo json_encode(['success' => true]);
