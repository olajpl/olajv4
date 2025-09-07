<?php
// admin/ai/ajax_chat.php — AJAX endpoint AI chatu (Olaj V4)
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';

use Engine\Ai\AiChatEngine;

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$userId  = (int)($_SESSION['user']['id'] ?? 0);
if ($ownerId <= 0 || $userId <= 0) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Brak dostępu.']); exit;
}

try {
    // PDO z bootstrapu:
    /** @var PDO $pdo */
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Brak PDO z bootstrapu.');
    }

    $engine = AiChatEngine::boot($pdo, $ownerId, $userId);

    $action = $_GET['action'] ?? '';
    if ($action === 'history') {
        $items = $engine->loadHistory(100);
        echo json_encode(['ok'=>true,'items'=>$items]); exit;
    }
    if ($action === 'clear') {
        $stmt = $pdo->prepare("DELETE FROM ai_chat_history WHERE owner_id=:o AND user_id=:u");
        $stmt->execute([':o'=>$ownerId, ':u'=>$userId]);
        echo json_encode(['ok'=>true]); exit;
    }

    // POST: message
    $csrf  = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'CSRF']); exit;
    }

    $msg = trim((string)($_POST['message'] ?? ''));
    if ($msg === '') { echo json_encode(['ok'=>false,'error'=>'Pusta wiadomość']); exit; }

    // Opcjonalny kontekst (np. product_id) — JSON w polu 'context'
    $contextJson = $_POST['context'] ?? null;
    $context = null;
    if ($contextJson) {
        $context = json_decode((string)$contextJson, true);
        if (!is_array($context)) $context = null;
    }

    // Zapis usera + call modelu
    $engine->saveMessage('user', $msg, $context);
    $reply = $engine->sendMessage($msg, $context);

    echo json_encode(['ok'=>true,'reply'=>$reply]);
} catch (Throwable $e) {
    if (!function_exists('logg')) {
        function logg(string $level, string $channel, string $message, array $context = [], array $extra = []): void {}
    }
    logg('error', 'ai.chat', 'ajax_chat error', ['error'=>$e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
