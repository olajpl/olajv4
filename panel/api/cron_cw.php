<?php
// api/cron_cw.php
// Jednorazowy „kick” CW: pobiera queued i odpala Cw::trySend()
// Zabezpieczenie: ?key=CRON_CW_KEY (ENV) lub stała poniżej.

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../engine/centralMessaging/Cw.php';

use CentralMessaging\Cw;

// 1) Sekret z ENV lub stała fallback:
$CONFIG_KEY = getenv('CRON_CW_KEY') ?: 'olaj_cw_2b4f4d4e1a6f4c7f9b2c0a1e';

// 2) Weryfikacja wywołania
$given = $_GET['key'] ?? '';
if (!hash_equals($CONFIG_KEY, (string)$given)) {
    logg('warning','cw.cron','unauthorized', ['ip'=>$_SERVER['REMOTE_ADDR'] ?? null], ['event'=>'cron_denied','source'=>'cron']);
    http_response_code(403);
    echo 'DENIED';
    exit;
}

// 3) Przerób batch queued → sent/failed
$processed = 0;
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->query("SELECT id FROM messages WHERE status='queued' ORDER BY id ASC LIMIT 50");
$ids  = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($ids as $id) {
    $processed++;
    Cw::trySend($pdo, (int)$id);
}

logg('info','cw.cron','kick_ok', ['processed'=>$processed], ['event'=>'cron_ok','source'=>'cron']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'processed'=>$processed], JSON_UNESCAPED_UNICODE);
