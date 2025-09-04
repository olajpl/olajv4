<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../includes/session.php';
require_once __DIR__ . '/../../../includes/db.php';

$live_id = (int)($_GET['live_id'] ?? 0);
$last_id = (int)($_GET['last_id'] ?? 0);
if ($live_id < 1) {
    http_response_code(400);
    exit;
}

@set_time_limit(0);
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

while (true) {
    $st = $pdo->prepare("SELECT id, display_name, message, created_at 
                       FROM live_messages 
                       WHERE live_id=? AND status='visible' AND id > ? 
                       ORDER BY id ASC LIMIT 100");
    $st->execute([$live_id, $last_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        $last_id = (int)end($rows)['id'];
        echo "event: messages\n";
        echo 'data: ' . json_encode(['items' => $rows, 'last_id' => $last_id], JSON_UNESCAPED_UNICODE) . "\n\n";
        @ob_flush();
        @flush();
    }

    // przerwij jeśli klient się rozłączył
    if (connection_aborted()) break;
    usleep(500000); // 0.5s
}
