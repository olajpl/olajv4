<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$client_id    = (int)($_POST['client_id'] ?? 0);
$owner_id     = (int)($_POST['owner_id'] ?? 0);
$platform     = $_POST['platform'] ?? 'chat';
$platform_id  = $_POST['platform_id'] ?? '';

if (!$client_id || empty($_FILES['image']['tmp_name'])) {
    die("❌ Brak danych lub pliku.");
}

$uploadDir = __DIR__ . '/../../uploads/messages/';
@mkdir($uploadDir, 0777, true); // utwórz katalog, jeśli nie istnieje

// Nazwa pliku i pełna ścieżka
$filename = uniqid('msg_') . '.jpg';
$targetPath = $uploadDir . $filename;
$imageUrl = '/uploads/messages/' . $filename;

if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO messages (owner_id, client_id, platform, platform_id, message, direction)
            VALUES (:owner_id, :client_id, :platform, :platform_id, :message, 'out')
        ");
        $stmt->execute([
            ':owner_id'     => $owner_id,
            ':client_id'    => $client_id,
            ':platform'     => $platform,
            ':platform_id'  => $platform_id,
            ':message'      => '[img]' . $imageUrl,
        ]);

        header("Location: view.php?client_id=" . $client_id);
        exit;

    } catch (Exception $e) {
        echo "❌ Błąd zapisu wiadomości: " . $e->getMessage();
    }
} else {
    echo "❌ Błąd przesyłania pliku.";
}
