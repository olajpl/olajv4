<?php
// engine/CentralMessaging/probe_cw.php
// Minimalny test CW – ręczne sprawdzenie sendMessengerNow()

declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';
require_once __DIR__ . '/../CentralMessaging/Cw.php';

use Engine\CentralMessaging\Cw;

header('Content-Type: application/json; charset=utf-8');

// 🔧 Ustawienia testowe — PODAJ WARTOŚCI ręcznie
$ownerId  = 1;                    // ← ustaw właściciela
$clientId = 37;                 // ← ustaw klienta (z przypisanym PSID)
$message  = '🚀 Test wiadomości z CW (probe_cw.php)'; // ← wiadomość testowa

try {
    $res = Cw::sendMessengerNow($pdo, $ownerId, $clientId, $message, [
        'event_key' => 'probe.test',
        'source'    => 'probe_cw',
    ]);
    echo json_encode(['status' => 'ok', 'res' => $res], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'status'  => 'error',
        'error'   => $e->getMessage(),
        'trace'   => $e->getTraceAsString(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
