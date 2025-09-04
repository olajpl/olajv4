<?php
// admin/live/ajax_add_comment.php — dodanie komentarza do live_comments
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

use Engine\Live\LiveEngine;

header('Content-Type: application/json');

$ownerId   = (int)($_SESSION['user']['owner_id'] ?? 0);
$operator  = (int)($_SESSION['user']['id'] ?? 0);
$liveId    = (int)($_POST['live_id'] ?? 0);
$clientId  = (int)($_POST['client_id'] ?? 0);
$message   = trim((string)($_POST['message'] ?? ''));

// walidacje
if ($ownerId <= 0 || $liveId <= 0 || $message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Brak wymaganych danych']);
    exit;
}

try {
    $commentId = LiveEngine::addComment($pdo, [
        'owner_id'         => $ownerId,
        'live_stream_id'   => $liveId,
        'client_id'        => $clientId ?: null,
        'source'           => 'manual',
        'message'          => $message,
        'sentiment'        => 'neu',
        'moderation'       => 'clean',
        'processed'        => 1,
        'is_command'       => 0,
        'command_type'     => null,
        'attachments_json' => null,
    ]);

    echo json_encode(['ok' => true, 'id' => $commentId]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'Błąd dodawania komentarza',
        'message' => $e->getMessage(),
    ]);
}
