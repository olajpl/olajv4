<?php
// zapisz_platnosc.php – zapis wyboru metody płatności dla klienta
require_once __DIR__ . '/includes/db.php';

$token = $_POST['token'] ?? '';
$group_id = $_POST['order_group_id'] ?? null;
$payment_method_id = $_POST['payment_method_id'] ?? null;

if (!$token || !$group_id || !$payment_method_id) {
    exit("Brakuje wymaganych danych.");
}

// sprawdź czy już istnieje płatność dla tej grupy
$stmt = $pdo->prepare("SELECT id FROM payments WHERE order_group_id = :group_id LIMIT 1");
$stmt->execute(['group_id' => $group_id]);
$exists = $stmt->fetchColumn();

if ($exists) {
    // aktualizacja metody płatności
    $stmt = $pdo->prepare("UPDATE payments SET method = :pmid, status = 'oczekujace' WHERE order_group_id = :group_id");
} else {
    // nowa płatność
    $stmt = $pdo->prepare("INSERT INTO payments (order_group_id, method, status) VALUES (:group_id, :pmid, 'oczekujace')");
}

$stmt->execute([
    'group_id' => $group_id,
    'pmid' => $payment_method_id
]);

// przekierowanie do widoku końcowego (dwa przyciski)
header("Location: moje.php?token=" . urlencode($token) . "&step=5");
exit();
