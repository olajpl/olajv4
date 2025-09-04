<?php

declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/autoload.php';

use Engine\Log\LogEngine;
use Engine\Orders\OrderEngine;
use Engine\Parser\ParserEngine;
use Engine\Webhook\WebhookEngine;
use Engine\CentralMessaging\Cw;

$pdo = $pdo ?? null;

try {
    if (function_exists('logg')) {
        logg('info', 'diag', 'Autoload probe start', [], ['context' => 'diag', 'source' => '_autoload_probe']);
    }

    // Klasy testowe — jeśli się nie wywalą, autoload działa
    class_exists(LogEngine::class);
    class_exists(OrderEngine::class);
    class_exists(ParserEngine::class);
    class_exists(WebhookEngine::class);
    class_exists(Cw::class);

    echo "✅ Autoload OK";
} catch (Throwable $e) {
    echo "❌ Autoload FAIL: " . $e->getMessage();
}
