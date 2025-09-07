<?php
// admin/orders/api/ai_add.php — Olaj V4
declare(strict_types=1);

require_once __DIR__ . '/../../../../bootstrap.php';

use Engine\Ai\AiOrderAssistant;
use Engine\Log\LogEngine;

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$userId  = (int)($_SESSION['user']['id'] ?? 0);
$csrf    = $_POST['csrf'] ?? '';

if (!$ownerId || !$userId) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'CSRF']); exit; }

$orderId = (int)($_POST['order_id'] ?? 0);
$groupId = (int)($_POST['group_id'] ?? 0);
$text    = trim((string)($_POST['text'] ?? ''));
$dryRun  = (bool)($_POST['dry'] ?? false);
$model   = ($_POST['model'] ?? null) ?: null;

try {
    $pdo = $GLOBALS['pdo']; // masz w bootstrap.php
    $assistant = AiOrderAssistant::boot($pdo, $ownerId, $userId, $model);
    $res = $assistant->handleTextForGroup($orderId, $groupId, $text, apply: !$dryRun);

    LogEngine::boot($pdo, $ownerId)->info('ai.order', 'api.ai_add', [
        'order_id'=>$orderId, 'group_id'=>$groupId, 'dry'=>$dryRun, 'len'=>mb_strlen($text)
    ]);

    echo json_encode(['ok'=>true] + $res, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    LogEngine::boot($GLOBALS['pdo'], $ownerId)->error('ai.order', 'api.ai_add.error', ['err'=>$e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
