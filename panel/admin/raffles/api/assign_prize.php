<?php
// ===== [admin/raffles/api/assign_prize.php] — przypisz nagrodę zwycięzcy =====
?>
<?php /* file: admin/raffles/api/assign_prize.php */ ?>
<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../engine/Marketing/RaffleEngine.php';

use Engine\Marketing\RaffleEngine;

header('Content-Type: application/json');
$res_id  = (int)($_POST['result_id'] ?? 0);
$prize_id = (int)($_POST['prize_id'] ?? 0);
if (!$res_id || !$prize_id) {
  http_response_code(422);
  echo json_encode(['error' => 'Brak danych']);
  exit;
}

try {
  $pdo->beginTransaction();
  // weryfikacja: wynik + zgodność draw_id
  $r = $pdo->prepare("SELECT draw_id FROM draw_results WHERE id=:id FOR UPDATE");
  $r->execute(['id' => $res_id]);
  $row = $r->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new Exception('Brak zwycięzcy');
  $did = (int)$row['draw_id'];
  $p = $pdo->prepare("SELECT quantity, reserved FROM draw_prizes WHERE id=:pid AND draw_id=:did FOR UPDATE");
  $p->execute(['pid' => $prize_id, 'did' => $did]);
  $pr = $p->fetch(PDO::FETCH_ASSOC);
  if (!$pr) throw new Exception('Nagroda nie istnieje lub inny draw');
  if ($pr['reserved'] >= $pr['quantity']) throw new Exception('Brak dostępnych sztuk');

  $pdo->prepare("UPDATE draw_prizes SET reserved = reserved + 1 WHERE id=:pid")->execute(['pid' => $prize_id]);
  $pdo->prepare("UPDATE draw_claims SET prize_id=:pid, claim_status=IF(claim_status='pending','claimed',claim_status), updated_at=NOW() WHERE result_id=:rid")
    ->execute(['pid' => $prize_id, 'rid' => $res_id]);
  $pdo->commit();
  echo json_encode(['success' => true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
?>