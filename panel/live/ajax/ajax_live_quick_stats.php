<?php
// admin/live/ajax/ajax_live_quick_stats.php
require_once __DIR__ . '/__live_boot.php';

try {
  $owner_id = (int)($_GET['owner_id'] ?? ($_SESSION['user']['owner_id'] ?? 0));
  $live_id  = (int)($_GET['live_id'] ?? 0);
  if ($live_id<=0) json_out(['items'=>0, 'reservations'=>0]);

  $st = $pdo->prepare("SELECT COUNT(*) FROM live_temp WHERE live_id=:lid" . ($owner_id>0?' AND owner_id=:oid':'' ));
  $params = [':lid'=>$live_id]; if ($owner_id>0) $params[':oid']=$owner_id;
  $st->execute($params);
  $items = (int)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COUNT(*) FROM stock_reservations WHERE source='live' AND live_id=:lid AND status='reserved'");
  $st->execute([':lid'=>$live_id]);
  $res = (int)$st->fetchColumn();

  json_out(['items'=>$items, 'reservations'=>$res]);
} catch (Throwable $e) {
  json_out(['items'=>0, 'reservations'=>0]);
}
