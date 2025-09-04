<?php
// admin/products/notify_client.php – Powiadom klienta z listy rezerwowej
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$reservation_id = (int)($_POST['reservation_id'] ?? 0);

if (!$reservation_id) {
  http_response_code(400);
  echo "Brak ID rezerwacji.";
  exit;
}

// Sprawdź, czy rezerwacja należy do właściciela
$stmt = $pdo->prepare("SELECT r.*, c.token, c.id as client_id, p.name AS product_name
  FROM product_reservations r
  JOIN clients c ON r.client_id = c.id
  JOIN products p ON r.product_id = p.id
  WHERE r.id = ?");
$stmt->execute([$reservation_id]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
  http_response_code(404);
  echo "Nie znaleziono rezerwacji.";
  exit;
}

if ($owner_id != $_SESSION['user']['owner_id']) {
  http_response_code(403);
  echo "Brak dostępu.";
  exit;
}

// Treść wiadomości
$message = "Hej {$reservation['product_name']} jest znowu dostępny! \nCzy chcesz dodać go do koszyka z listy rezerwowej?";
$buttons = [
  ["title" => "✅ Tak", "payload" => "waitlist_yes_{$reservation['product_id']}_{$reservation['id']}"],
  ["title" => "❌ Nie", "payload" => "waitlist_no_{$reservation['product_id']}_{$reservation['id']}"],
];

// Wysyłka wiadomości z przyciskami (Messenger lub inna platforma)
sendStructuredMessage($pdo, $owner_id, $reservation['client_id'], $message, $buttons);

// Zaktualizuj status i czas powiadomienia
$stmt = $pdo->prepare("UPDATE product_reservations SET status = 'powiadomiony', notified_at = NOW() WHERE id = ?");
$stmt->execute([$reservation_id]);

// Przekierowanie z komunikatem
$_SESSION['success_message'] = "Klient został oznaczony jako powiadomiony i otrzymał wiadomość.";
header("Location: waitlist.php?product_id=" . $reservation['product_id']);
exit;
