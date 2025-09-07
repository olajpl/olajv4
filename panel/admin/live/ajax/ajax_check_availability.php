<?php
declare(strict_types=1);

// ROOT względem /admin/live/ajax
define('APP_ROOT', dirname(__DIR__, 3));

require_once __DIR__ . '/../../../../bootstrap.php';

use Engine\Products\ProductEngine;

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
$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($owner_id <= 0) {
    json_out(['success' => false, 'error' => 'missing_owner'], 400);
}

    $engine = new ProductEngine($pdo);

    // Produkty custom -> dostępność „nieskończona”
if (!$product_id && !$code) {
    json_out([
        'success'    => true,
        'ok'         => true,
        'reason'     => 'custom_product',
        'available'  => 999999,   // „dużo”, bez ryzyka w JS
        'infinite'   => true,     // flaga dla frontu
        'reserved'   => 0,
        'requested'  => $qty,
        'product_id' => null
    ]);
}

// Ustal product_id (jeśli podano code)
$engine = new ProductEngine($pdo);
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

// 🧷 Bezpieczny SELECT na różne schematy kolumn
// Priorytet: stock_available (generated) -> fallback: stock_cached - stock_reserved_cached -> fallback: stock - stock_reserved
$sql = "
  SELECT
    p.stock_available                                         AS available_calc,
    COALESCE(p.stock_reserved_cached, 0)                      AS reserved_calc
  FROM products p
  WHERE p.id = :pid AND p.owner_id = :oid
  LIMIT 1
";
$st = $pdo->prepare($sql);
$st->execute([':pid' => $product_id, ':oid' => $owner_id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  json_out([
    'success'    => true,
    'ok'         => false,
    'reason'     => 'not_found',
    'available'  => 0,
    'reserved'   => 0,
    'requested'  => $qty,
    'product_id' => null
  ]);
}

$available     = max(0.0, (float)$row['available_calc']);   // DECIMAL → float
$stockReserved = max(0.0, (float)$row['reserved_calc']);

json_out([
  'success'    => true,
  'ok'         => ($available >= $qty),
  'reason'     => ($available >= $qty ? 'enough' : 'insufficient'),
  'available'  => $available,
  'reserved'   => $stockReserved,
  'requested'  => $qty,
  'product_id' => $product_id
]);
} catch (Throwable $e) {
    if (function_exists('logg')) {
        logg('error', 'live.availability', 'Błąd availability', [
            'owner_id' => $owner_id ?? null,
            'product_id' => $product_id ?? null,
            'code' => $code ?? null,
        ], ['exception' => $e->getMessage()]);
    }
    json_out(['success' => false, 'error' => 'exception', 'message' => $e->getMessage()], 500);
}
