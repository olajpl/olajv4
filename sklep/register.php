<?php
// konto/register.php – rejestracja klienta Olaj.pl
require_once __DIR__ . '/../includes/db.php';

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$redirect = $_POST['redirect'] ?? '/index.php';

if (!$name) {
  die('⚠️ Podaj imię.');
}

// Wygeneruj unikalny token
$base = 'olaj';
do {
  $token = $base . rand(1000, 9999);
  $stmt = $pdo->prepare("SELECT id FROM clients WHERE token = ?");
  $stmt->execute([$token]);
} while ($stmt->fetch());

$stmt = $pdo->prepare("INSERT INTO clients (name, email, phone, token, created_at) VALUES (?, ?, ?, ?, NOW())");
$stmt->execute([$name, $email, $phone, $token]);

setcookie('client_token', $token, time() + (86400 * 30), "/");
header("Location: $redirect");
exit;
