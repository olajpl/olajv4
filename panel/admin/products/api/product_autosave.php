<?php
// admin/products/api/product_autosave.php — autosave szkicu produktu (Olaj.pl V4)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../../engine/Product/ProductEngine.php';

use Engine\Product\ProductEngine;

if (session_status() === PHP_SESSION_NONE) session_start();

// ───────────────────────────────────────────────────────────────
// Auth / CSRF
// ───────────────────────────────────────────────────────────────
$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$user_id  = (int)($_SESSION['user']['id'] ?? 0);
if ($owner_id <= 0 || $user_id <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$csrf_form = (string)($_POST['csrf_token'] ?? '');
$csrf_sess = (string)($_SESSION['csrf_token'] ?? '');
if ($csrf_form === '' || $csrf_sess === '' || !hash_equals($csrf_sess, $csrf_form)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf_fail']);
    exit;
}

// ───────────────────────────────────────────────────────────────
// Helpers
// ───────────────────────────────────────────────────────────────
function dec($v): float
{
    // "12,34" → 12.34, " 1 234.5 " → 1234.5
    $s = str_replace([' ', ','], ['', '.'], (string)$v);
    return round((float)$s, 2);
}
function map_status_to_active(string $status): int
{
    switch ($status) {
        case 'inactive':
        case 'draft':
            return 0;
        case 'deleted':
            return 0; // autosave NIE usuwa; tylko zdejmuje z aktywności
        case 'active':
        default:
            return 1;
    }
}

// ───────────────────────────────────────────────────────────────
// Input z formularza edycji
// ───────────────────────────────────────────────────────────────
$product_id     = (int)($_POST['id'] ?? 0);
$name_in        = isset($_POST['name']) ? trim((string)$_POST['name']) : null;
$code_in        = isset($_POST['code']) ? trim((string)$_POST['code']) : null;
$unit_price_in  = $_POST['unit_price']  ?? null;
$vat_rate_in    = $_POST['vat_rate']    ?? null;
$category_id_in = $_POST['category_id'] ?? null;
$status_in      = isset($_POST['status']) ? (string)$_POST['status'] : 'active';
$available_from = (string)($_POST['available_from'] ?? ''); // może być puste
$weight_kg_ui   = $_POST['weight_kg_ui'] ?? null;           // preferowane w UI
$weight_grams   = $_POST['weight_grams'] ?? null;           // rzadko przy autosave
$tags_in        = $_POST['tags'] ?? null;                   // array|NULL

if ($product_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_product']);
    exit;
}

try {
    $engine  = new ProductEngine($pdo, $owner_id);
    $current = $engine->getById($product_id);
    if (!$current) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }

    // Zadbaj o wymagane pola Engine::update (name, code) — uzupełnij z bazy jeśli puste
    $name = ($name_in !== null && $name_in !== '') ? $name_in : (string)$current['name'];
    $code = ($code_in !== null && $code_in !== '') ? $code_in : (string)$current['code'];
    if ($name === '' || $code === '') {
        // nie ryzykuj nieudanym update — autosave ma być cichy
        echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'missing_name_or_code']);
        exit;
    }

    // Liczby
    $unit_price = ($unit_price_in !== null && $unit_price_in !== '') ? dec($unit_price_in) : (float)($current['unit_price'] ?? $current['price'] ?? 0);
    $vat_rate   = ($vat_rate_in   !== null && $vat_rate_in   !== '') ? dec($vat_rate_in)   : (float)($current['vat_rate'] ?? 23);

    // Kategoria
    $category_id = null;
    if ($category_id_in !== null && $category_id_in !== '') {
        $category_id = (int)$category_id_in ?: null;
    } else {
        $category_id = isset($current['category_id']) ? (int)$current['category_id'] : null;
    }

    // Waga w KG (tak przyjmuje Engine::update)
    $weight_kg = null;
    if ($weight_kg_ui !== null && $weight_kg_ui !== '') {
        $weight_kg = (float)str_replace(',', '.', (string)$weight_kg_ui);
    } elseif ($weight_grams !== null && $weight_grams !== '') {
        $weight_kg = ((float)$weight_grams) / 1000.0;
    }

    // Status → active (bez kasowania przy autosave)
    $active = map_status_to_active($status_in);

    // Zbuduj payload; NIE podajemy stock_available (żeby nie wywołać korekty)
    $payload = [
        'name'           => $name,
        'code'           => $code,
        'unit_price'     => $unit_price,
        'vat_rate'       => $vat_rate,
        'category_id'    => $category_id,
        'active'         => $active,
        'available_from' => $available_from !== '' ? $available_from : null,
    ];
    if ($weight_kg !== null) {
        $payload['weight'] = $weight_kg; // Engine sam wybierze kolumnę (grams/kg)
    }

    $engine->update($product_id, $payload);

    // Tagi (jeśli przyleciały w payloadzie autosave)
    if (is_array($tags_in)) {
        $tagIds = [];
        foreach ($tags_in as $t) {
            $iv = (int)$t;
            if ($iv > 0) $tagIds[] = $iv;
        }
        $engine->setTags($product_id, $tagIds);
    }

    echo json_encode([
        'ok'        => true,
        'mode'      => 'autosave',
        'product_id' => $product_id,
        'updated'   => array_keys($payload),
        'ts'        => date('c'),
    ]);
} catch (\Throwable $e) {
    logg('error', 'products.autosave', 'exception', [
        'product_id' => $product_id,
        'owner_id'   => $owner_id,
        'error'      => $e->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'exception', 'detail' => $e->getMessage()]);
}
