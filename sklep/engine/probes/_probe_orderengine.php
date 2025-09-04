<?php
// engine/probes/_probe_orderengine.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../Orders/OrderEngine.php';
require_once __DIR__ . '/../Enum/OrderStatus.php';
require_once __DIR__ . '/../Enum/Column.php';

use Engine\Orders\OrderEngine;
use Engine\Enum\OrderStatus;
use Engine\Enum\Column;

header('Content-Type: text/plain; charset=utf-8');

$ownerId       = 1;        // 🔁 zmień na ID właściciela
$clientId      = 12345;    // 🔁 zmień na istniejącego client_id z bazy
$operatorUserId = 99;      // 🔁 przykładowy user_id

echo "=== PROBE: findOrCreateOpenGroupForLive ===\n";

try {
    $engine = new OrderEngine($pdo);

    $result = $engine->findOrCreateOpenGroupForLive($ownerId, $clientId, $operatorUserId);

    echo "✔ Wynik: \n";
    print_r($result);
} catch (Throwable $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ':' . $e->getLine() . "\n\n";

    echo "🔍 Trace:\n" . $e->getTraceAsString();
}
