<?php
// admin/products/api/stock_adjust.php — szybka korekta stanu (Olaj.pl V4)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';
require_once __DIR__ . '/../../../engine/Product/ProductEngine.php';

use Engine\Product\ProductEngine;

if (session_status() === PHP_SESSION_NONE) session_start();

// ———————————————————————————————————————————————————————————
// Auth
// ———————————————————————————————————————————————————————————
$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$user_id  = (int)($_SESSION['user']['id'] ?? 0);
if ($owner_id <= 0 || $user_id <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

// ———————————————————————————————————————————————————————————
// Input: JSON lub x-www-form-urlencoded
// akceptujemy: id | product_id, delta | difference, new_stock, csrf_token, note
// ———————————————————————————————————————————————————————————
$raw = file_get_contents('php://input');
$payload = [];
if ($raw !== '' && ($rawData = json_decode($raw, true)) && is_array($rawData)) {
    $payload = $rawData;
} else {
    $payload = $_POST;
}

$csrf_form   = (string)($payload['csrf_token'] ?? '');
$csrf_sess   = (string)($_SESSION['csrf_token'] ?? '');
if ($csrf_form === '' || $csrf_sess === '' || !hash_equals($csrf_sess, $csrf_form)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf_fail']);
    exit;
}

$product_id  = (int)($payload['id'] ?? $payload['product_id'] ?? 0);
$note        = trim((string)($payload['note'] ?? 'Korekta (panel)'));
$hasNewStock = array_key_exists('new_stock', $payload) && $payload['new_stock'] !== '';
$delta_in    = $payload['delta'] ?? $payload['difference'] ?? null;

if ($product_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_product']);
    exit;
}

try {
    $engine = new ProductEngine($pdo, $owner_id);

    // Odczytaj bieżące stany (cached + reserved)
    $before = $engine->getStockStatus($product_id);
    if ($before === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }

    // Wyznacz deltę:
    // 1) jeśli podano new_stock → delta = new_stock - before.stock_cached
    // 2) inaczej bierz delta z payloadu
    $delta = 0;

    if ($hasNewStock) {
        // new_stock może być liczbą lub struną — normalizujemy
        $new_stock_num = (float)str_replace(',', '.', (string)$payload['new_stock']);
        // ProduktEngine::moveStock przyjmuje int qty — zaokrąglamy
        $new_stock = (int)round($new_stock_num);
        $delta     = $new_stock - (int)$before['stock_cached'];
    } else {
        if ($delta_in === null || $delta_in === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'missing_delta']);
            exit;
        }
        $delta = (int)round((float)str_replace(',', '.', (string)$delta_in));
    }

    if ($delta === 0) {
        // nic do zrobienia
        echo json_encode([
            'ok'      => true,
            'noop'    => true,
            'before'  => $before,
            'after'   => $before,
            'message' => 'delta=0'
        ]);
        exit;
    }

    // Ruch magazynowy — engine zadba o to, by nie zejść poniżej rezerwacji
    $movement_id = $engine->moveStock($product_id, 'adjust', $delta, $note);

    // Po operacji — stany „po”
    $after = $engine->getStockStatus($product_id) ?? $before;

    echo json_encode([
        'ok'           => true,
        'product_id'   => $product_id,
        'movement_id'  => $movement_id,
        'delta'        => $delta,
        'before'       => $before,
        'after'        => $after,
        'ts'           => date('c'),
    ]);
} catch (\Throwable $e) {
    // Specjalny przypadek: zejście poniżej rezerwacji
    $msg = $e->getMessage();
    if ($msg === 'stock_would_lt_reserved' || $msg === 'stock_lt_reserved') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'stock_lt_reserved', 'detail' => $msg]);
        exit;
    }

    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'exception', 'detail' => $e->getMessage()]);
}
