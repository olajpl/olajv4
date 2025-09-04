<?php
// admin/raffles/api/draw.php — safe deterministyczny draw (bez ujemnych indeksów, bez warningów)
declare(strict_types=1);

ini_set('display_errors', '0'); // nie hałasujemy na output; błędy wyłapujemy w JSON
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  http_response_code(422);
  echo json_encode(['error' => 'Brak ID']);
  exit;
}

try {
  $pdo->beginTransaction();

  // Lock draw
  $st = $pdo->prepare("SELECT * FROM draws WHERE id=:id FOR UPDATE");
  $st->execute([':id' => $id]);
  $draw = $st->fetch(PDO::FETCH_ASSOC);
  if (!$draw) throw new Exception('Brak losowania.');
  if ($draw['status'] !== 'frozen') throw new Exception('Najpierw wykonaj freeze.');

  // Entries (ważone)
  $e = $pdo->prepare("
        SELECT platform, platform_id, display_name, COALESCE(weight,1) AS weight
        FROM draw_entries
        WHERE draw_id=:id
        ORDER BY id ASC
    ");
  $e->execute([':id' => $id]);
  $entries = $e->fetchAll(PDO::FETCH_ASSOC);
  if (!$entries) throw new Exception('Brak uczestników.');

  // Commit-reveal: seed = sha256(json(entries)+salt)
  $json = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $salt = isset($draw['reveal_salt']) ? (string)$draw['reveal_salt'] : '';
  $seed = hash('sha256', $json . $salt);

  // u64 z seeda (bez ujemnych indeksów)
  $h = hash('sha256', $seed . ':0');
  $hi = substr($h, 0, 16);
  $bin = hex2bin($hi);
  if ($bin === false) throw new Exception('Błąd seeda.');
  $arr = unpack('J', $bin); // unsigned 64-bit
  $u64 = (int)$arr[1];

  // wybór ważony
  $sumW = 0;
  foreach ($entries as $row) {
    $sumW += max(1, (int)$row['weight']);
  }
  if ($sumW <= 0) $sumW = count($entries);

  $target = $u64 % $sumW;
  $idx = 0;
  $acc = 0;
  foreach ($entries as $i => $row) {
    $acc += max(1, (int)$row['weight']);
    if ($target < $acc) {
      $idx = $i;
      break;
    }
  }

  // winner (bez NULL-i)
  $w = $entries[$idx] ?? null;
  if (!$w) throw new Exception('Nie udało się wyznaczyć zwycięzcy.');

  $platform   = trim((string)($w['platform'] ?? '')) ?: 'manual';
  $platformId = trim((string)($w['platform_id'] ?? ''));
  $display    = trim((string)($w['display_name'] ?? '')) ?: 'Uczestnik';

  // zapis result
  $ins = $pdo->prepare("
      INSERT INTO draw_results
        (draw_id, owner_id, live_stream_id, platform, platform_id, display_name, winner_index, won_at, created_at)
      VALUES
        (:did, :oid, :lid, :pf, :pid, :name, :idx, NOW(), NOW())
    ");
  $ins->execute([
    ':did' => $id,
    ':oid' => (int)$draw['owner_id'],
    ':lid' => $draw['live_stream_id'],
    ':pf'  => $platform,
    ':pid' => ($platformId === '' ? null : $platformId),
    ':name' => $display,
    ':idx' => $idx,
  ]);
  $resultId = (int)$pdo->lastInsertId();

  // claim pending
  $cl = $pdo->prepare("
      INSERT INTO draw_claims
        (result_id, draw_id, claim_status, claim_token, fulfillment_channel, claimant_platform, claimant_platform_id, created_at, updated_at)
      VALUES
        (:rid, :did, 'pending', REPLACE(UUID(),'-',''), 'none', :pf, :pid, NOW(), NOW())
    ");
  $cl->execute([
    ':rid' => $resultId,
    ':did' => $id,
    ':pf' => $platform,
    ':pid' => ($platformId === '' ? null : $platformId),
  ]);
  $claimId = (int)$pdo->lastInsertId();

  // update draw
  $upd = $pdo->prepare("UPDATE draws SET status='drawn', seed_hex=:seed, drawn_at=NOW(), updated_at=NOW() WHERE id=:id");
  $upd->execute([':seed' => $seed, ':id' => $id]);

  $pdo->commit();

  echo json_encode([
    'success' => true,
    'winner' => [
      'display_name' => $display,
      'platform' => $platform,
      'platform_id' => $platformId,
    ],
    'seed' => $seed,
    'index' => $idx,
    'result_id' => $resultId,
    'claim_id' => $claimId,
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
