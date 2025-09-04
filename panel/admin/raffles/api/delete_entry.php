<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../engine/Marketing/RaffleEngine.php';

use Engine\Marketing\RaffleEngine;

header('Content-Type: application/json');

$draw_id  = (int)($_POST['draw_id'] ?? 0);
$entry_id = (int)($_POST['entry_id'] ?? 0);
if (!$draw_id || !$entry_id) {
  http_response_code(422);
  echo json_encode(['error' => 'Brak danych']);
  exit;
}

// sprawdź status (tylko w arming)
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

  // Usuń wpis (jeśli należy do tego losowania)
  $del = $pdo->prepare("DELETE FROM draw_entries WHERE id=:eid AND draw_id=:did");
  $del->execute(['eid' => $entry_id, 'did' => $draw_id]);

  if ($del->rowCount() > 0) {
    // dekrementuj licznik
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
