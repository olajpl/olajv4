<?php

use CentralMessaging\Cw;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../engine/centralMessaging/Cw.php';

$owner_id  = (int)($_SESSION['user']['owner_id'] ?? 0);
$client_id = (int)($_POST['client_id'] ?? 0);
$channel   = $_POST['channel'] ?? 'sms';
$event_key = $_POST['event_key'] ?? null;
$payload   = $_POST['payload'] ?? []; // order_id, order_group_id, subject/body_text/body_html etc.

$msgId = Cw::enqueue($pdo, [
  'owner_id' => $owner_id,
  'client_id'=> $client_id,
  'channel'  => $channel,
  'context'  => 'cw',
  'event_key'=> $event_key,
  'template_id' => $payload['template_id'] ?? null,
  'order_id'    => $payload['order_id'] ?? null,
  'order_group_id' => $payload['order_group_id'] ?? null,
  'platform_thread_id' => $payload['to'] ?? ($payload['platform_thread_id'] ?? null), // ⬅️ TU NOWE
  'subject'     => $payload['subject'] ?? null,
  'body_text'   => $payload['body_text'] ?? null,
  'body_html'   => $payload['body_html'] ?? null,
]);

use \Engine\Log\LogEngine::boot($pdo, $owner_id)
    ->notice('cw', 'API enqueue called', [
        'message_id' => $msgId,
        'channel'    => $channel,
    ], [
        'context'  => 'cw',
        'owner_id' => $owner_id,
        'user_id'  => $_SESSION['user']['id'] ?? null,
        'event'    => 'api_enqueue',
    ]);

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'message_id' => $msgId]);
