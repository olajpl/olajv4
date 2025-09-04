<?php
// admin/live/ajax/ajax_delete_live_product.php
require_once __DIR__ . '/__live_boot.php';

try {
  $id      = (int)($_POST['id'] ?? 0);
  $live_id = (int)($_POST['live_id'] ?? 0);
  if ($id<=0 || $live_id<=0) json_out(['success'=>false, 'error'=>'Brak ID/LIVE']);

  $pdo->beginTransaction();

  // Pobierz wiersz
  $st = $pdo->prepare("SELECT * FROM live_temp WHERE id=:id AND live_id=:lid LIMIT 1");
  $st->execute([':id'=>$id, ':lid'=>$live_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('Nie znaleziono pozycji.');

  // Zwolnij rezerwację jeśli katalogowa
  if (($row['source_type'] ?? '') === 'catalog') {
    $pid = (int)$row['product_id'];
    $resId = (int)($row['reservation_id'] ?? 0);
    $qty = (int)$row['qty'];

    if ($pid>0 && $qty>0) {
      // - stock_reserved--
      $pdo->prepare("UPDATE products SET stock_reserved = GREATEST(0, stock_reserved - :q) WHERE id=:pid")->execute([':q'=>$qty, ':pid'=>$pid]);
    }
    if ($resId>0) {
      $pdo->prepare("UPDATE stock_reservations SET status='released', released_at=NOW() WHERE id=:rid")->execute([':rid'=>$resId]);
    }
  }

  // Usuń live_temp
  $pdo->prepare("DELETE FROM live_temp WHERE id=:id")->execute([':id'=>$id]);
  $pdo->commit();

  json_out(['success'=>true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_out(['success'=>false, 'error'=>$e->getMessage()], 200);
}
