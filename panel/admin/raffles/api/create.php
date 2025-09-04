<?php
// admin/raffles/api/create.php — zapis przez RaffleEngine
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';
require_once __DIR__ . '/../../../engine/Marketing/RaffleEngine.php';

use Engine\Marketing\RaffleEngine;

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 1);

// CSRF (jeśli używasz)
if (!empty($_SESSION['csrf'])) {
  $csrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($_SESSION['csrf'], $csrf)) {
    http_response_code(419);
    echo json_encode(['error' => 'CSRF']);
    exit;
  }
}

$title       = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$liveId      = (int)($_POST['live_stream_id'] ?? 0) ?: null;
$allowDup    = (int)($_POST['allow_duplicates'] ?? 0);
$cooldown    = max(0, (int)($_POST['cooldown_days'] ?? 7));
$keyword     = trim((string)($_POST['keyword'] ?? ''));

if ($title === '') {
  http_response_code(422);
  echo json_encode(['error' => 'Brak tytułu']);
  exit;
}

try {
  $engine = new RaffleEngine($pdo, $ownerId);
  $drawId = $engine->createDraw([
    'title'                => $title,
    'description'          => $description ?: null,
    'live_stream_id'       => $liveId,
    'allow_duplicates'     => $allowDup,
    'cooldown_days'        => $cooldown,
    'keyword'              => $keyword ?: null,
    'created_by_admin_id'  => (int)($_SESSION['user']['id'] ?? 0) ?: null,
  ]);

  // po utworzeniu — od razu otwórz do zbierania
  $engine->openForEntries($drawId);

  // Redirect JSON (frontend może przechwycić i przenieść na view.php)
  echo json_encode(['success' => true, 'draw_id' => $drawId, 'redirect' => "../view.php?id={$drawId}"]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
