<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die("Błędne ID");
}

// Pobierz obecny status
$stmt = $pdo->prepare("SELECT active FROM product_sets WHERE id = :id AND owner_id = :owner_id");
$stmt->execute(['id' => $id, 'owner_id' => $owner_id]);
$set = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$set) {
    die("Nie znaleziono zestawu");
}

$new_status = $set['active'] ? 0 : 1;

// Zapisz nowy status
$stmt = $pdo->prepare("UPDATE product_sets SET active = :status WHERE id = :id AND owner_id = :owner_id");
$stmt->execute(['status' => $new_status, 'id' => $id, 'owner_id' => $owner_id]);

header("Location: product_sets.php");
exit;
