<?php

declare(strict_types=1);

/**
 * /api/products/info.php
 * GET params:
 *   - id (int, wymagany) — ID produktu
 *
 * Output (application/json):
 * {
 *   "ok": true,
 *   "data": {
 *     "id": 123,
 *     "name": "Nazwa",
 *     "price": 19.99,
 *     "price_formatted": "19,99 PLN",
 *     "currency": "PLN",
 *     "stock": 5,
 *     "weight_kg": 0.25,
 *     "description": "<p>Opis...</p>",
 *     "images": ["https://cdn/.../1.jpg", "..."]
 *   }
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/log.php'; // jeżeli masz w projekcie

// --- helper: bezpieczna odpowiedź i exit ---
function json_out(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- helper: kolumna istnieje? ---
function table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table) . '|' . strtolower($column);
    if (isset($cache[$key])) return $cache[$key];
    $st = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t
          AND COLUMN_NAME = :c
        LIMIT 1
    ");
    $st->execute([':t' => $table, ':c' => $column]);
    return $cache[$key] = (bool)$st->fetchColumn();
}

// --- helper: kompatybilny odczyt ustawień ownera ---
if (!function_exists('shopSettings')) {
    function shopSettings(PDO $pdo, int $ownerId): array
    {
        if (function_exists('getShopSettings')) {
            try {
                $rf = new ReflectionFunction('getShopSettings');
                if ($rf->getNumberOfParameters() === 1) {
                    return getShopSettings($ownerId);          // stara sygnatura
                } else {
                    return getShopSettings($pdo, $ownerId);    // nowa sygnatura
                }
            } catch (Throwable $e) {
            }
        }
        // Fallback: czytamy owner_settings ręcznie
        $out = [];
        try {
            $tExists = $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='owner_settings' LIMIT 1");
            if (!$tExists || !$tExists->fetchColumn()) return [];
            $q = $pdo->prepare("SELECT `key`,`value`,`type` FROM owner_settings WHERE owner_id=:oid");
            $q->execute([':oid' => $ownerId]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $val = $r['value'];
                switch ($r['type']) {
                    case 'int':
                        $val = (int)$val;
                        break;
                    case 'float':
                        $val = (float)$val;
                        break;
                    case 'bool':
                        $val = in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on'], true);
                        break;
                    case 'json':
                        $d = json_decode((string)$val, true);
                        if (json_last_error() === JSON_ERROR_NONE) $val = $d;
                        break;
                }
                $out[$r['key']] = $val;
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}

// --- owner scope ---
$client    = function_exists('autoLoginFromCookie') ? (autoLoginFromCookie() ?: []) : [];
$owner_id  = (int)($client['owner_id'] ?? 1); // jeżeli masz getOwnerId(), możesz tu z niego skorzystać
$id        = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id < 1) {
    json_out(400, ['ok' => false, 'error' => 'Missing or invalid product id']);
}

// --- sprawdzamy, które kolumny mamy ---
$has_desc   = table_has_column($pdo, 'products', 'description');
$has_weight = table_has_column($pdo, 'products', 'weight_kg');
$has_price  = table_has_column($pdo, 'products', 'unit_price');
$has_active = table_has_column($pdo, 'products', 'is_active') || table_has_column($pdo, 'products', 'active');

$stockExpr = '0';
if (table_has_column($pdo, 'products', 'stock_available')) {
    $stockExpr = 'p.stock_available';
} elseif (table_has_column($pdo, 'products', 'stock_cached') && table_has_column($pdo, 'products', 'stock_reserved_cached')) {
    $stockExpr = '(p.stock_cached - COALESCE(p.stock_reserved_cached,0))';
} elseif (table_has_column($pdo, 'products', 'stock_cached')) {
    $stockExpr = 'p.stock_cached';
}

// --- budujemy SELECT pod posiadane kolumny ---
$select = "SELECT p.id, p.name";
$select .= $has_price ? ", COALESCE(p.unit_price,0) AS price" : ", 0.0 AS price";
$select .= ", {$stockExpr} AS stock";
$select .= $has_weight ? ", p.weight_kg" : ", NULL AS weight_kg";
$select .= $has_desc   ? ", p.description" : ", NULL AS description";
$whereActive = '1=1';
if (table_has_column($pdo, 'products', 'is_active')) {
    $whereActive = 'p.is_active=1';
} elseif (table_has_column($pdo, 'products', 'active')) {
    $whereActive = 'p.active=1';
}

$sql = "{$select} FROM products p WHERE p.owner_id = :oid AND p.id = :id AND {$whereActive} LIMIT 1";
$st  = $pdo->prepare($sql);
$st->execute([':oid' => $owner_id, ':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    json_out(404, ['ok' => false, 'error' => 'Product not found']);
}

// --- settings (currency, cdn) ---
$settings = shopSettings($pdo, $owner_id);
$currency = (string)($settings['currency'] ?? 'PLN');
$cdn      = rtrim((string)($settings['cdn_url'] ?? 'https://panel.olaj.pl'), '/');

// --- obrazki (główne i dodatkowe) ---
$images = [];
try {
    $hasPi = $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_images' LIMIT 1");
    if ($hasPi && $hasPi->fetchColumn()) {
        $img = $pdo->prepare("
            SELECT image_path
            FROM product_images
            WHERE product_id = :pid
            ORDER BY is_main DESC, id ASC
        ");
        $img->execute([':pid' => $id]);
        foreach ($img->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ir) {
            $rel = trim((string)($ir['image_path'] ?? ''));
            if ($rel !== '') {
                $images[] = $cdn . '/uploads/products/' . ltrim($rel, '/');
            }
        }
    }
} catch (Throwable $e) {
    // brak tabeli obrazków nie jest błędem
}
if (!$images) {
    $images[] = 'https://via.placeholder.com/800x600?text=Brak+zdj%C4%99cia';
}

// --- opis: jeśli w DB jest HTML, przepuszczamy; jeśli nie, esc + nl2br ---
$description = (string)($row['description'] ?? '');
if ($description !== '') {
    $looksHtml = preg_match('~</?[a-z][\s\S]*>~i', $description) === 1;
    if (!$looksHtml) {
        $description = nl2br(htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}

// --- wynik ---
$price         = (float)($row['price'] ?? 0);
$priceFormatted = number_format($price, 2, ',', ' ') . ' ' . $currency;
$stock         = (int)max(0, (int)($row['stock'] ?? 0));
$weight        = isset($row['weight_kg']) ? (float)$row['weight_kg'] : null;

$data = [
    'id'              => (int)$row['id'],
    'name'            => (string)$row['name'],
    'price'           => $price,
    'price_formatted' => $priceFormatted,
    'currency'        => $currency,
    'stock'           => $stock,
    'weight_kg'       => $weight,
    'description'     => $description,
    'images'          => $images,
];

// log (opcjonalnie)
if (function_exists('logg')) {
    logg('info', 'api.products.info', 'product_info', ['owner_id' => $owner_id, 'product_id' => $id]);
}

json_out(200, ['ok' => true, 'data' => $data]);
