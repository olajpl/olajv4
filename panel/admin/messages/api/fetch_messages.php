<?php
// admin/messages/api/fetch_messages.php — Olaj V4
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$owner_id  = (int)($_SESSION['user']['owner_id'] ?? 0);
$client_id = (int)($_GET['client_id'] ?? 0);

$as = strtolower((string)($_GET['as'] ?? ''));

// ───────────────────────────────────────────────────────────────
// Guardy
// ───────────────────────────────────────────────────────────────
if ($owner_id <= 0 || $client_id <= 0) {
  if ($as === 'json') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'bad_params'], JSON_UNESCAPED_UNICODE);
  } else {
    http_response_code(400);
    echo '❌ Brak owner_id lub client_id.';
  }
  exit;
}

// upewnij się, że klient należy do ownera
$stC = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND owner_id = ? LIMIT 1');
$stC->execute([$client_id, $owner_id]);
if (!$stC->fetchColumn()) {
  if ($as === 'json') {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'client_not_found'], JSON_UNESCAPED_UNICODE);
  } else {
    http_response_code(404);
    echo '❌ Klient nie znaleziony.';
  }
  exit;
}

// ───────────────────────────────────────────────────────────────
// Pobranie wiadomości (UWAGA: content AS message)
// ───────────────────────────────────────────────────────────────
try {
  // limit kontrolowany z GET (opcjonalnie)
  $limit = (int)($_GET['limit'] ?? 500);
  if ($limit < 1 || $limit > 2000) $limit = 500;

  $sql = "
    SELECT
      m.id,
      m.direction,
      m.channel,
      m.platform,
      m.content AS message,
      m.created_at
      /* brak kolumny reaction w schemacie — jeżeli kiedyś będzie: , m.reaction */
    FROM messages m
    WHERE m.owner_id  = :owner
      AND m.client_id = :client
    ORDER BY m.created_at ASC, m.id ASC
    LIMIT {$limit}
  ";
  $st = $pdo->prepare($sql);
  $st->execute(['owner' => $owner_id, 'client' => $client_id]);
  $messages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  try {
    logg('error', 'messages.api.fetch', 'DB error fetching messages', [
      'owner_id'  => $owner_id,
      'client_id' => $client_id,
      'err'       => $e->getMessage(),
    ]);
  } catch (Throwable $__) {
  }
  if ($as === 'json') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
  } else {
    http_response_code(500);
    echo '❌ Błąd bazy (fetch_messages).';
  }
  exit;
}

// ───────────────────────────────────────────────────────────────
// Wyjścia: JSON / HTML (dymki)
// ───────────────────────────────────────────────────────────────
if ($as === 'json') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($messages, JSON_UNESCAPED_UNICODE);
  exit;
}

// HTML render (server-side)
function h(?string $s): string
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool
  {
    return strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}

foreach ($messages as $msg):
  $dir     = (string)($msg['direction'] ?? 'in');
  $align   = ($dir === 'out') ? 'justify-end' : 'justify-start';
  $bubble  = ($dir === 'out') ? 'bubble bubble-out' : 'bubble bubble-in';
  $text    = (string)($msg['message'] ?? ''); // ← alias z content
  $created = (string)($msg['created_at'] ?? '');
  echo '<div class="flex ' . $align . '">';
  echo   '<div class="' . $bubble . '">';

  if ($text !== '' && str_starts_with($text, '[img]')) {
    $url = trim(str_replace('[img]', '', $text));
    echo '<img src="' . h($url) . '" alt="img" class="rounded max-w-xs">';
  } else {
    echo h($text);
  }

  // Czas
  if ($created !== '') {
    echo '<div class="text-xs text-gray-400 mt-1">' . h(date('H:i d.m', strtotime($created))) . '</div>';
  }

  echo   '</div>';
  echo '</div>';
endforeach;
