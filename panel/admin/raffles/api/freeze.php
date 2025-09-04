<?php
// ===== [admin/raffles/api/freeze.php] — commit–reveal: freeze =====
?>
<?php /* file: admin/raffles/api/freeze.php */ ?>
<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../engine/Marketing/RaffleEngine.php';

use Engine\Marketing\RaffleEngine;

header('Content-Type: application/json');

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
  http_response_code(422);
  echo json_encode(['error' => 'Brak ID']);
  exit;
}

try {
  $q = $pdo->prepare("SELECT status FROM draws WHERE id=:id FOR UPDATE");
  $pdo->beginTransaction();
  $q->execute(['id' => $id]);
  $row = $q->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    throw new Exception('Brak losowania');
  }
  if ($row['status'] !== 'arming') {
    throw new Exception('Losowanie nie jest w trybie arming');
  }

  $e = $pdo->prepare("SELECT platform, platform_id, display_name, weight FROM draw_entries WHERE draw_id=:id ORDER BY id ASC");
  $e->execute(['id' => $id]);
  $entries = $e->fetchAll(PDO::FETCH_ASSOC);
  $json = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $salt = bin2hex(random_bytes(16));
  $commit = hash('sha256', $json . $salt);

  $u = $pdo->prepare("UPDATE draws SET status='frozen', commit_hash=:c, reveal_salt=:s, updated_at=NOW() WHERE id=:id");
  $u->execute(['c' => $commit, 's' => $salt, 'id' => $id]);
  $pdo->commit();
  echo json_encode(['success' => true, 'commit' => $commit]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
?>