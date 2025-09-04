<?php
// text/event-stream + CORS/cache off
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$owner_id  = (int)($_SESSION['user']['owner_id'] ?? 0);
$client_id = (int)($_GET['client_id'] ?? 0);
if ($owner_id <= 0 || $client_id <= 0) {
    http_response_code(401);
    exit;
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no'); // nginx: wyłącz buforowanie
echo ":" . str_repeat(" ", 2048) . "\n\n"; // warm-up dla niektórych proxy
@ob_flush();
@flush();

// Ostatnio wysłany znacznik czasu (z Last-Event-ID lub query)
$since = $_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['since'] ?? '0');
$since = preg_replace('/[^\d\-:\s]/', '', (string)$since); // prosty sanity check
if ($since === '0') $since = date('Y-m-d H:i:s', time() - 3600); // start: ostatnia godzina

// Pętla SSE (long-running)
$clientKey = $owner_id . ':' . $client_id;
$lastId = $since;

while (!connection_aborted()) {
    // 1) DB polling co 2s (albo: tutaj podłącz Redis subscribe)
    $st = $pdo->prepare("
    SELECT id, direction, channel, platform, content AS message, created_at
    FROM messages
    WHERE owner_id=:o AND client_id=:c AND created_at > :since
    ORDER BY created_at ASC, id ASC
    LIMIT 200
  ");
    $st->execute(['o' => $owner_id, 'c' => $client_id, 'since' => $lastId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($rows) {
        foreach ($rows as $msg) {
            $eventId = (string)($msg['created_at']); // lub "{$msg['id']}"
            $payload = json_encode($msg, JSON_UNESCAPED_UNICODE);
            echo "id: {$eventId}\n";
            echo "event: message\n";
            echo "data: {$payload}\n\n";
            $lastId = $eventId;
        }
        @ob_flush();
        @flush();
    }

    // 2) ping co 15s (utrzymanie połączenia)
    static $tick = 0;
    if (($tick++ % 7) === 0) {
        echo "event: ping\ndata: ok\n\n";
        @ob_flush();
        @flush();
    }

    sleep(2);
}
