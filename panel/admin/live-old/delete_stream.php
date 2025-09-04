<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$stream_id = (int)($_GET['id'] ?? 0);

if ($stream_id <= 0) {
    die('Brak ID transmisji.');
}

$pdo->beginTransaction();
try {
    // Sprawdź, czy transmisja należy do danego ownera
    $check = $pdo->prepare("SELECT id FROM live_streams WHERE id = ? AND owner_id = ?");
    $check->execute([$stream_id, $owner_id]);
    if (!$check->fetch()) {
        throw new Exception('Nie znaleziono transmisji.');
    }

    // Usuń transmisję (lub oznacz jako usuniętą, jeśli chcesz logikę soft-delete)
    $stmt = $pdo->prepare("DELETE FROM live_streams WHERE id = ? AND owner_id = ?");
    $stmt->execute([$stream_id, $owner_id]);

    $pdo->commit();
    header("Location: index.php");
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Błąd: " . $e->getMessage();
}
