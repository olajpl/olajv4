<?php
declare(strict_types=1);

/**
 * admin/live/ajax/ajax_presenter_prefill.php
 * IN (GET): owner_id?, product_id? | code?
 * OUT: { success, product_id, title, price, vat_rate, code, pitch, bullets[], image, images[] }
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__, 3));

require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/engine/orders/ProductEngine.php';

use Engine\Orders\ProductEngine;

// ==== config obrazków (dostosuj jeśli masz inną ścieżkę) ====
const PROD_UPLOAD_BASE = '/uploads/products/';

// ---- helpers ----
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
if (!function_exists('txt')) {
    function txt(?string $s): string {
        $s = (string)($s ?? '');
        $s = strip_tags($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }
}
if (!function_exists('truncate')) {
    function truncate(string $s, int $len = 200): string {
        if (mb_strlen($s, 'UTF-8') <= $len) return $s;
        $cut = mb_substr($s, 0, $len, 'UTF-8');
        $sp  = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($sp !== false && $sp > 0) $cut = mb_substr($cut, 0, $sp, 'UTF-8');
        return rtrim($cut, " \t\n\r\0\x0B.,;:!-") . '…';
    }
}
if (!function_exists('bullets_from')) {
    function bullets_from(?string $short, ?string $desc): array {
        $candidates = [];
        $short = txt($short);
        $desc  = txt($desc);

        if ($short !== '') {
            $parts = preg_split('/[•\-\n\r;]+/u', $short) ?: [];
            foreach ($parts as $p) { $p = trim($p); if ($p !== '') $candidates[] = $p; }
        }
        if (count($candidates) < 3 && $desc !== '') {
            $sent = preg_split('/(?<=[\.\!\?])\s+/u', $desc) ?: [];
            foreach ($sent as $s) {
                $s = trim($s);
                if ($s !== '' && mb_strlen($s, 'UTF-8') >= 15) $candidates[] = $s;
                if (count($candidates) >= 3) break;
            }
        }
        $uniq = array_values(array_unique($candidates));
        return array_slice($uniq, 0, 3);
    }
}
if (!function_exists('img_url_norm')) {
    function img_url_norm(?string $path): string {
        $p = trim((string)$path);
        if ($p === '') return '';
        if (str_starts_with($p, 'http://') || str_starts_with($p, 'https://')) return $p;
        return rtrim(PROD_UPLOAD_BASE, '/') . '/' . ltrim($p, '/');
    }
}
if (!function_exists('noimg_svg')) {
    function noimg_svg(): string {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="120"><rect width="100%" height="100%" fill="#f1f5f9"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="sans-serif" font-size="12" fill="#64748b">brak zdjęcia</text></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}

try {
    $owner_id   = (int)($_GET['owner_id'] ?? ($_SESSION['user']['owner_id'] ?? 0));
    $product_id = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? (int)$_GET['product_id'] : null;
    $code       = isset($_GET['code']) ? trim((string)$_GET['code']) : null;

    if ($owner_id <= 0) json_out(['success' => false, 'error' => 'missing_owner'], 400);
    if (!$product_id && !$code) json_out(['success' => false, 'error' => 'missing_product_id_or_code'], 400);

    $engine = new ProductEngine($pdo);
    $resolvedId = $engine->getProductId($owner_id, $product_id, $code);
    if (!$resolvedId) json_out(['success' => false, 'error' => 'not_found'], 404);

    $row = $engine->getProduct($resolvedId);
    if (!$row || (int)($row['owner_id'] ?? 0) !== $owner_id) {
        json_out(['success' => false, 'error' => 'not_found_or_forbidden'], 404);
    }

    $name  = (string)($row['name'] ?? '');
    $price = (float)($row['price'] ?? ($row['unit_price'] ?? 0));
    $vat   = (float)($row['vat_rate'] ?? 23);
    $pcode = (string)($row['code'] ?? ($row['sku'] ?? ($row['barcode'] ?? ($row['twelve_nc'] ?? ''))));
    $desc  = (string)($row['description'] ?? '');
    $short = (string)($row['short_description'] ?? '');

    // ==== obrazki ====
    $imgStmt = $pdo->prepare("
        SELECT image_path, alt_text, is_main
        FROM product_images
        WHERE product_id = :pid
        ORDER BY is_main DESC, sort_order ASC, id ASC
        LIMIT 8
    ");
    $imgStmt->execute(['pid' => $resolvedId]);
    $imgRows = $imgStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $images = [];
    foreach ($imgRows as $ir) {
        $url = img_url_norm($ir['image_path'] ?? '');
        if ($url === '') continue;
        $images[] = [
            'url'     => $url,
            'alt'     => (string)($ir['alt_text'] ?? ''),
            'is_main' => (int)($ir['is_main'] ?? 0),
        ];
    }
    // główne zdjęcie (pierwsze z listy) lub fallback SVG
    $mainImg = $images[0]['url'] ?? noimg_svg();

    $title   = $name !== '' ? $name : ('Produkt #' . $resolvedId);
    $pitch   = truncate(txt($desc), 200);
    $bullets = bullets_from($short, $desc);

    json_out([
        'success'    => true,
        'product_id' => $resolvedId,
        'title'      => $title,
        'price'      => $price,
        'vat_rate'   => $vat,
        'code'       => $pcode,
        'pitch'      => $pitch,
        'bullets'    => $bullets,
        'image'      => $mainImg,
        'images'     => $images,
    ]);
} catch (Throwable $e) {
    json_out(['success' => false, 'error' => 'exception', 'message' => $e->getMessage()], 500);
}
