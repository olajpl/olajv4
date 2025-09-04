<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../engine/Webhook/WebhookEngine.php';

use Engine\Webhook\WebhookEngine;

try {
    $ownerId = 1;
    $engine = new WebhookEngine($pdo, $ownerId);
    $sample = [
        'object' => 'page',
        'entry' => [[
            'id' => '1234567890',
            'time' => time() * 1000,
            'messaging' => [[
                'sender' => ['id' => 'PSID_TEST_1'],
                'recipient' => ['id' => '1234567890'],
                'timestamp' => time() * 1000,
                'message' => ['mid' => 'm_test_' . time(), 'text' => 'daj 123 x2']
            ]]
        ]]
    ];
    $raw = json_encode($sample);
    $headers = ['x-facebook-delivery-id' => 'probe-' . time()];
    $out = $engine->ingestFacebook($raw, $headers);
    echo json_encode(['ok' => true, 'result' => $out], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'line' => $e->getLine(), 'file' => $e->getFile()]);
}
