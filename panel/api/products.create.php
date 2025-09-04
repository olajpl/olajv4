<?php

declare(strict_types=1);

// api/product.create.php
// JSON API: tworzenie produktu

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';
require_once __DIR__ . '/../../engine/Product/ProductEngine.php';

use Engine\Product\ProductEngine;

header('Content-Type: application/json; charset=utf-8');

try {
    // autoryzacja (sesja)
    $ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
    if ($ownerId <= 0) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'no_owner']);
        exit;
    }

    // input JSON
    $raw = file_get_contents('php://input') ?: '{}';
    $in  = json_decode($raw, true);
    if (!is_array($in)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_json']);
        exit;
    }

    // prosta walidacja
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'name_required']);
        exit;
    }

    // normalizacja liczb (kropka vs przecinek)
    $unit_price = (string)($in['unit_price'] ?? '0');
    $unit_price = str_replace(',', '.', $unit_price);
    $vat_rate   = (string)($in['vat_rate'] ?? '23');
    $vat_rate   = str_replace(',', '.', $vat_rate);

    $payload = [
        'name'            => $name,
        'code'            => trim((string)($in['code'] ?? '')),
        'unit_price'      => $unit_price,
        'vat_rate'        => $vat_rate,
        'category_id'     => isset($in['category_id']) && $in['category_id'] !== '' ? (int)$in['category_id'] : null,
        'stock_available' => (int)($in['stock_available'] ?? 0),
        'active'          => (int)($in['active'] ?? 1),
    ];

    $engine = ProductEngine::boot($pdo, $ownerId);
    $result = $engine->create($payload);

    echo json_encode(['ok' => true, 'id' => (int)$result['id']]);
} catch (Throwable $e) {
    // log do centralnego loggera
    logg('error', 'product.api', 'create_failed', [
        'ex' => $e->getMessage()
    ], ['context' => 'products', 'source' => 'api']);

    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'exception']);
}
