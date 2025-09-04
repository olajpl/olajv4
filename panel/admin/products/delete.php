<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$id = $_GET['id'] ?? null;

if (!$id) {
    header("Location: index.php");
    exit;
}

// Bezpieczne usuwanie tylko w obrębie ownera
$stmt = $pdo->prepare("DELETE FROM products WHERE id = :id AND owner_id = :owner_id");
$stmt->execute([
    'id' => $id,
    'owner_id' => $owner_id
]);

$_SESSION['success_message'] = "Produkt został usunięty.";
header("Location: index.php");
exit;
