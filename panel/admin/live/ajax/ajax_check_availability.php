<?php
declare(strict_types=1);

// ROOT względem /admin/live/ajax
define('APP_ROOT', dirname(__DIR__, 3));

require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/engine/Orders/ProductEngine.php';

use Engine\Orders\ProductEngine;

// Polyfill json_out, gdyby helper nie był wciągnięty
if (!function_exists('json_out')) {
    function json_out(array $payload, int $status = 200): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

try {
    // Czytamy GET/POST (frontend wysyła GET w live.js)
    $owner_id   = (int)($_REQUEST['owner_id'] ?? 0);
    $product_id = isset($_REQUEST['product_id']) && $_REQUEST['product_id'] !== '' ? (int)$_REQUEST['product_id'] : null;
    $code       = isset($_REQUEST['code']) ? trim((string)$_REQUEST['code']) : null;
    $qty        = (int)($_REQUEST['qty'] ?? 1);
    if ($qty < 1) $qty = 1;

    // Fallback z sesji (operator zalogowany)
    if ($owner_id <= 0 && !empty($_SESSION['user']['owner_id'])) {
        $owner_id = (int)$_SESSION['user']['owner_id'];
    }
    if ($owner_id <= 0) {
        json_out(['success' => false, 'error' => 'missing_owner'], 400);
    }

    $engine = new ProductEngine($pdo);

    // Produkty custom -> dostępne "nieskończoność"
    if (!$product_id && !$code) {
        json_out([
            'success'   => true,
            'ok'        => true,
            'reason'    => 'custom_product',
            'available' => PHP_INT_MAX,
            'reserved'  => 0,
            'requested' => $qty,
            'product_id'=> null
        ]);
    }

    // Ustal product_id (jeśli podano code)
    $resolvedId = $engine->getProductId($owner_id, $product_id, $code);
    if (!$resolvedId) {
        json_out([
            'success'   => true,
            'ok'        => false,
            'reason'    => 'not_found',
            'available' => 0,
            'reserved'  => 0,
            'requested' => $qty,
            'product_id'=> null
        ]);
    }

    // Pobierz stock i reserved
    $st = $pdo->prepare("SELECT stock, stock_reserved FROM products WHERE id = :id AND owner_id = :owner LIMIT 1");
    $st->execute(['id' => $resolvedId, 'owner' => $owner_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        json_out([
            'success'   => true,
            'ok'        => false,
            'reason'    => 'not_found',
            'available' => 0,
            'reserved'  => 0,
            'requested' => $qty,
            'product_id'=> null
        ]);
    }

    $stock         = (float)$row['stock'];
    $stockReserved = (float)$row['stock_reserved'];
    $available     = max(0, $stock - $stockReserved);

    json_out([
        'success'   => true,
        'ok'        => ($available >= $qty),
        'reason'    => ($available >= $qty ? 'enough' : 'insufficient'),
        'available' => $available,
        'reserved'  => $stockReserved,
        'requested' => $qty,
        'product_id'=> $resolvedId
    ]);
} catch (Throwable $e) {
    json_out(['success' => false, 'error' => 'exception', 'message' => $e->getMessage()], 500);
}
