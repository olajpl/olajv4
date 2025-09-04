<?php
require_once '/../engine/Notifications/NotificationEngine.php';
require_once '/../engine/Enum/NotificationEvent.php';
require_once '/../engine/CentralMessaging/Cw.php';
require_once '/../..includes/log.php';
require_once '/../../includes/db.php';

$noti = new \Engine\Notifications\NotificationEngine($pdo, new \Engine\CentralMessaging\Cw($pdo), new \Engine\Log\LogEngine($pdo));

$noti->dispatch([
  'owner_id' => 1,
  'event_key' => \Engine\Enum\NotificationEvent::ORDER_MISSING_DATA->value,
  'context' => [
    'client_id' => 37,
    'order_id' => 37,
    'checkout_url' => 'https://olaj.pl/checkout/summary.php?token=abc123',
    'missing_fields' => ['ulica', 'miasto'],
  ],
]);
