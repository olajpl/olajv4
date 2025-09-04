<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$title = trim($_POST['title'] ?? '');
$platform = $_POST['platform'] ?? '';
$url = trim($_POST['stream_url'] ?? '');

$valid_platforms = ['facebook', 'youtube', 'tiktok'];

if ($title === '' || $url === '' || !in_array($platform, $valid_platforms, true)) {
    die('Błąd: nieprawidłowe dane.');
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        INSERT INTO live_streams
        (owner_id, title, platform, stream_url, status, created_at)
        VALUES (?, ?, ?, ?, 'planned', NOW())
    ");
    $stmt->execute([$owner_id, $title, $platform, $url]);
    $id = (int)$pdo->lastInsertId();

    $pdo->commit();
    header("Location: view.php?id=$id");
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Wystąpił błąd: " . $e->getMessage();
}
