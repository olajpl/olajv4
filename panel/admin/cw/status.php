<?php
// admin/cw/status.php — Zwraca aktualny status wiadomości w JSON
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);

// Walidacja
if ($id <= 0 || $ownerId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => null, 'error' => 'Invalid request']);
    exit;
}

try {
    $st = $pdo->prepare("SELECT status FROM messages WHERE id = ? AND owner_id = ? LIMIT 1");
    $st->execute([$id, $ownerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        echo json_encode(['status' => $row['status']]);
    } else {
        http_response_code(404);
        echo json_encode(['status' => null, 'error' => 'Not found']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => null, 'error' => 'Server error']);
}
