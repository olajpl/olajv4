<?php
// cron/cw_worker.php – centralny wysyłacz
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../engine/centralMessaging/Cw.php';

use CentralMessaging\Cw;

// pobierz 20 najstarszych queued, które są już „do spróbowania”
$stmt = $pdo->prepare("
    SELECT id
    FROM messages
    WHERE status='queued'
      AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
    ORDER BY created_at ASC
    LIMIT 20
");
$stmt->execute();
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($ids as $id) {
    Cw::trySend($pdo, (int)$id);
}
