<?php
// api/webhook_fb.php — Olaj V4 webhook (Facebook: GET verify + POST ingest)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// 1) Bootstrap
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../engine/Log/LogEngine.php';
require_once __DIR__ . '/../engine/autoload.php';

// (Jeśli nie masz PSR-4 w całym projekcie, możesz zachować te require’y,
// ale przy autoloaderze nie są konieczne):
@require_once __DIR__ . '/../engine/CentralMessaging/CwHelper.php';
@require_once __DIR__ . '/../engine/Parser/ParserEngine.php';
@require_once __DIR__ . '/../engine/Webhook/WebhookEngine.php';

use Engine\Webhook\WebhookEngine;

// 2) Heartbeat do logów (zanim cokolwiek zrobimy)
logg('info', 'webhook', 'ingest.start', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
    'qs'     => $_SERVER['QUERY_STRING'] ?? null,
], [
    'owner_id' => 1,
    'context'  => 'webhook',
    'source'   => 'api/webhook_fb.php'
]);

// 3) Owner resolve
$headers = function_exists('getallheaders') ? getallheaders() : [];
$ownerId = WebhookEngine::resolveOwnerId(
    $pdo,
    isset($_GET['owner_id']) ? (int)$_GET['owner_id'] : null,
    $headers
);

$engine = new WebhookEngine($pdo, $ownerId);

// 4) GET = verify (Facebook webhook handshake)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    try {
        $engine->handleVerify($_GET);
        // handleVerify robi echo challenge + exit wewnątrz,
        // ale gdyby nie, to:
        exit;
    } catch (Throwable $e) {
        logg('error', 'webhook', 'verify.fail', [
            '_ex_message' => $e->getMessage(),
            '_ex_file'    => $e->getFile(),
            '_ex_line'    => $e->getLine(),
        ], ['owner_id' => $ownerId, 'context' => 'webhook']);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'verify_failed']);
        exit;
    }
}

// 5) POST = real payload
try {
    $raw = file_get_contents('php://input') ?: '';
    $engine->handlePost($raw, $headers);
    // handlePost powinno samo zadbać o status 200 / echo 'OK' jeśli trzeba.
    if (!headers_sent()) {
        http_response_code(200);
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    logg('error', 'webhook', 'post.fail', [
        '_ex_message' => $e->getMessage(),
        '_ex_file'    => $e->getFile(),
        '_ex_line'    => $e->getLine(),
        'preview'     => isset($raw) ? mb_substr($raw, 0, 500) : null,
    ], ['owner_id' => $ownerId, 'context' => 'webhook']);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'ingest_failed']);
}
