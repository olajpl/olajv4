<?php
require_once __DIR__ . '/../../../../bootstrap.php'

$message_id = (int)($_POST['message_id'] ?? 0);
$reaction = $_POST['reaction'] ?? null;

// Ustawienie lub wyczyszczenie reakcji
$stmt = $pdo->prepare("UPDATE message_reactions SET reaction = :reaction WHERE id = :id AND owner_id = :owner_id");
$stmt->execute([
    ':reaction' => $reaction ?: null,
    ':id' => $message_id,
    ':owner_id' => $_SESSION['user']['owner_id'] ?? 0
]);

echo 'OK';
