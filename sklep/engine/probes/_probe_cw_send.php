<?php

declare(strict_types=1);

use Engine\CentralMessaging\Cw;

require_once __DIR__ . '/../CentralMessaging/Cw.php';
require_once __DIR__ . '/../../includes/db.php';   // Twój bootstrap PDO
require_once __DIR__ . '/../../includes/log.php';  // logg()

$ownerId  = (int)($_GET['owner'] ?? 1);
$clientId = (int)($_GET['client'] ?? 0);
$text     = (string)($_GET['text'] ?? 'Probe: hello from CW');

if ($clientId <= 0) {
    echo "Podaj ?client=ID w URL.\n";
    exit;
}

try {
    // 1) kolejka
    Cw::enqueueMessenger($pdo, $ownerId, $clientId, [
        'event_key' => 'probe.test',
        'text'      => $text,
        'priority'  => 'normal',
    ]);
    echo "enqueueMessenger OK\n";

    // 2) synchronicznie
    Cw::sendMessengerNow($pdo, $ownerId, $clientId, $text);
    echo "sendMessengerNow OK\n";
} catch (Throwable $e) {
    echo "ERR: " . $e->getMessage() . "\n";
}
