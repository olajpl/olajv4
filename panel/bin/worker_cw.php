#!/usr/bin/env php
<?php
require __DIR__.'/../includes/db.php';
require __DIR__.'/../includes/log.php';
require __DIR__.'/../engine/centralMessaging/Cw.php';

use CentralMessaging\Cw;

while (true) {
  $st = $pdo->query("SELECT id FROM messages WHERE status='queued' ORDER BY id ASC LIMIT 50");
  $ids = $st->fetchAll(PDO::FETCH_COLUMN);
  if (!$ids) { sleep(5); continue; }
  foreach ($ids as $id) {
    Cw::trySend($pdo, (int)$id);
  }
}
