<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../engine/Marketing/RaffleEngine.php';

use Engine\Marketing\RaffleEngine;

header('Content-Type: application/json');

$draw_id     = (int)($_POST['draw_id'] ?? 0);
$entry_id    = (int)($_POST['entry_id'] ?? 0);
$platform    = trim($_POST['platform'] ?? '');
$platform_id = trim($_POST['platform_id'] ?? '');
$reason      = trim($_POST['reason'] ?? 'Panel: ręczny ban');

if (!$draw_id || !$entry_id) {
  http_response_code(422);
  echo json_encode(['error' => 'Brak danych']);
  exit;
}

// sprawdź losowanie i status
$st = $pdo->prepare("SELECT owner_id, status FROM draws WHERE id=:id LIMIT 1");
$st->execute(['id' => $draw_id]);
$draw = $st->fetch(PDO::FETCH_ASSOC);
if (!$draw) {
  http_response_code(404);
  echo json_encode(['error' => 'Brak losowania']);
  exit;
}
if ($draw['status'] !== 'arming') {
  http_response_code(409);
  echo json_encode(['error' => 'Edycja niedostępna — lista zamknięta']);
  exit;
}

try {
  $pdo->beginTransaction();

  // Pobierz entry (dla pewności platform danych, jeśli nie przyszły)
  $q = $pdo->prepare("SELECT platform, platform_id FROM draw_entries WHERE id=:eid AND draw_id=:did LIMIT 1");
  $q->execute(['eid' => $entry_id, 'did' => $draw_id]);
  $row = $q->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    throw new Exception('Nie znaleziono wpisu');
  }
  $pf  = $platform    !== '' ? $platform    : (string)$row['platform'];
  $pid = $platform_id !== '' ? $platform_id : (string)$row['platform_id'];

  // Dodaj do ban-listy (idempotentnie)
  $ins = $pdo->prepare("INSERT INTO draw_bans (owner_id, platform, platform_id, reason, created_by_admin_id)
                        VALUES (:oid,:pf,:pid,:r,:aid)
                        ON DUPLICATE KEY UPDATE reason=VALUES(reason), created_by_admin_id=VALUES(created_by_admin_id)");
  $ins->execute([
    'oid' => $draw['owner_id'],
    'pf' => $pf,
    'pid' => $pid,
    'r' => $reason,
    'aid' => ($_SESSION['user']['id'] ?? null)
  ]);

  // Usuń wpis z listy uczestników tego losowania
  $del = $pdo->prepare("DELETE FROM draw_entries WHERE id=:eid AND draw_id=:did");
  $del->execute(['eid' => $entry_id, 'did' => $draw_id]);

  if ($del->rowCount() > 0) {
    $pdo->prepare("UPDATE draws SET participants_count = GREATEST(participants_count-1,0) WHERE id=:id")
      ->execute(['id' => $draw_id]);
  }

  $pdo->commit();
  echo json_encode(['success' => true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
