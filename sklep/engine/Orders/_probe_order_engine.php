<?php
// engine/orders/_probe_order_engine.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';
require_once __DIR__ . '/OrderEngine.php';

use Engine\Orders\OrderEngine;

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
        exit;
    }

    // Minimalny payload – podepnij realny product_id/klienta
    $ownerId  = (int)($_POST['owner_id'] ?? 1);
    $clientId = (int)($_POST['client_id'] ?? 1);
    $name     = trim((string)($_POST['name'] ?? 'Test product'));
    $qty      = (float)($_POST['qty'] ?? 1.0);
    $price    = (float)($_POST['unit_price'] ?? 9.99);
    $vat      = (float)($_POST['vat_rate'] ?? 23.0);

    $res = OrderEngine::addOrderItem($pdo, [
        'owner_id'   => $ownerId,
        'client_id'  => $clientId,
        'product_id' => isset($_POST['product_id']) ? (int)$_POST['product_id'] : null,
        'sku'        => $_POST['sku'] ?? null,
        'name'       => $name,
        'qty'        => $qty,
        'unit_price' => $price,
        'vat_rate'   => $vat,
        'source_type' => $_POST['source_type'] ?? 'manual',
        'source_id'  => isset($_POST['source_id']) ? (int)$_POST['source_id'] : null,
        'combine_mode' => $_POST['combine_mode'] ?? 'follow_order_status',
        'preferred_payment_method_id' => isset($_POST['preferred_payment_method_id']) ? (int)$_POST['preferred_payment_method_id'] : null,
    ]);

    echo json_encode($res, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (function_exists('logg')) {
        logg('error', 'orders', 'probe_failed', ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()]);
}
