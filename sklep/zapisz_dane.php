<?php
// zapisz_dane.php – zapis danych adresowych klienta do shipping_labels
require_once __DIR__ . '/includes/db.php';

$order_id = $_POST['order_id'] ?? null;
$token = $_POST['token'] ?? null;

$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$street = trim($_POST['street'] ?? '');
$postcode = trim($_POST['postcode'] ?? '');
$city = trim($_POST['city'] ?? '');

if (!$order_id || !$token || !$name || !$phone || !$street || !$postcode || !$city) {
    exit("Brakuje wymaganych danych.");
}

// sprawdź czy już istnieje labelka dla tego zamówienia
$stmt = $pdo->prepare("SELECT id FROM shipping_labels WHERE order_id = :order_id LIMIT 1");
$stmt->execute(['order_id' => $order_id]);
$exists = $stmt->fetchColumn();

if ($exists) {
    // aktualizacja
    $stmt = $pdo->prepare("UPDATE shipping_labels SET receiver_name = :name, receiver_phone = :phone, receiver_email = :email, receiver_street = :street, receiver_postcode = :postcode, receiver_city = :city WHERE order_id = :order_id");
} else {
    // dodanie nowej
    $stmt = $pdo->prepare("INSERT INTO shipping_labels (order_id, provider, receiver_name, receiver_phone, receiver_email, receiver_street, receiver_postcode, receiver_city) VALUES (:order_id, 'manual', :name, :phone, :email, :street, :postcode, :city)");
}

$stmt->execute([
    'order_id' => $order_id,
    'name' => $name,
    'phone' => $phone,
    'email' => $email,
    'street' => $street,
    'postcode' => $postcode,
    'city' => $city
]);

// przekierowanie do kroku płatności
header("Location: moje.php?token=" . urlencode($token) . "&step=4");
exit();
