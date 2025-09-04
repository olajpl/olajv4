<?php

use CentralMessaging\Cw;

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../engine/centralMessaging/Cw.php';

$incomingId = (int)($_GET['id'] ?? 0);
$channel    = $_GET['channel'] ?? 'messenger'; // messenger|sms|email
$text       = $_GET['text']    ?? 'Thanks for your message!';

if (!$incomingId) {
    die('Brak id');
}

$st = $pdo->prepare("SELECT * FROM messages WHERE id=? LIMIT 1");
$st->execute([$incomingId]);
$in = $st->fetch(PDO::FETCH_ASSOC);
if (!$in) die('Nie znaleziono');

$msgId = Cw::enqueue($pdo, [
    'owner_id'            => (int)$in['owner_id'],
    'client_id'           => (int)$in['client_id'],
    'order_id'            => $in['order_id'],
    'order_group_id'      => $in['order_group_id'],
    'channel'             => $channel,
    'context'             => 'cw',
    'platform_thread_id'  => $in['platform_thread_id'], // dla messengera
    'subject'             => null,
    'body_text'           => $text,
    'body_html'           => null,
    'template_id'         => null,
    'event_key'           => null,
]);

echo json_encode(['queued_id' => $msgId]);
