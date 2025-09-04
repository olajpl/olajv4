<?php
declare(strict_types=1);

// admin/live/ajax/ajax_presenter_search.php
define('APP_ROOT', dirname(__DIR__, 3));
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/db.php';

if (!function_exists('json_out')) {
    function json_out($payload, int $status=200): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if (!function_exists('s')) {
    function s(?string $v): string { return trim((string)$v); }
}
if (!function_exists('starts_with')) {
    function starts_with(string $haystack, string $needle): bool {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

// INPUT
$q        = s($_GET['q'] ?? '');
$owner_id = (int)($_GET['owner_id'] ?? ($_SESSION['user']['owner_id'] ?? 0));
if ($owner_id <= 0) json_out(['error'=>'missing_owner'], 400);
if ($q === '') { json_out([]); }

// Heurystyka trybu
$mode = 'name';
if (preg_match('/^\d{8,14}$/', $q))             $mode = 'barcode';   // EAN
elseif (preg_match('/^\d{6,}$/', $q))           $mode = 'twelve_nc'; // 12NC / podobne
elseif (preg_match('/^[A-Z0-9\-_]{3,}$/i', $q)) $mode = 'code';      // SKU/kod

// Warunek WHERE zależny od trybu (unikalne placeholdery!)
$where = '';
$params = [ 'owner' => $owner_id ];

if ($mode === 'name') {
    $where = '(p.name LIKE :l1 OR p.code LIKE :l2 OR p.sku LIKE :l3 OR p.twelve_nc LIKE :l4 OR p.barcode LIKE :l5)';
    $like = '%'.$q.'%';
    $params += ['l1'=>$like, 'l2'=>$like, 'l3'=>$like, 'l4'=>$like, 'l5'=>$like];
} elseif ($mode === 'code') {
    $where = '(p.code LIKE :l1 OR p.sku LIKE :l2)';
    $like = '%'.$q.'%';
    $params += ['l1'=>$like, 'l2'=>$like];
} elseif ($mode === 'barcode') {
    $where = 'p.barcode = :q1';
    $params += ['q1'=>$q];
} else { // twelve_nc
    $where = 'p.twelve_nc = :q1';
    $params += ['q1'=>$q];
}

// SQL z miniaturą (pierwsze zdjęcie: is_main DESC, sort_order, id)
$sql = "
SELECT 
  p.id,
  p.name,
  p.code,
  p.sku,
  p.barcode,
  p.twelve_nc,
  p.price,
  (
    SELECT pi.image_path 
    FROM product_images pi 
    WHERE pi.product_id = p.id
    ORDER BY pi.is_main DESC, pi.sort_order ASC, pi.id ASC
    LIMIT 1
  ) AS thumb
FROM products p
WHERE p.owner_id = :owner
  AND p.active = 1
  AND p.visibility <> 'archived'
  AND $where
ORDER BY p.name ASC
LIMIT 25
";

$st = $pdo->prepare($sql);
$st->execute($params);

$out = [];
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $textParts = [];
    if (!empty($r['name']))      $textParts[] = $r['name'];
    if (!empty($r['code']))      $textParts[] = 'CODE '.$r['code'];
    if (!empty($r['sku']))       $textParts[] = 'SKU '.$r['sku'];
    if (!empty($r['twelve_nc'])) $textParts[] = '12NC '.$r['twelve_nc'];
    if (!empty($r['barcode']))   $textParts[] = 'EAN '.$r['barcode'];
    if ($r['price'] !== null)    $textParts[] = number_format((float)$r['price'], 2, ',', ' ').' zł';
    $text = implode(' · ', $textParts);

    // URL miniatury
    $thumb = $r['thumb'] ?? null;
    if ($thumb && !preg_match('~^https?://~i', $thumb)) {
        if (starts_with($thumb, '/')) {
            // absolute od root-a hosta – zostaw
        } else {
            $thumb = '/uploads/products/'.$thumb; // dostosuj, jeśli masz inną ścieżkę
        }
    }

    $out[] = [
        'id'        => (int)$r['id'],
        'text'      => $text !== '' ? $text : ('Produkt #'.(int)$r['id']),
        'thumb'     => $thumb ?: null,
        'name'      => $r['name'] ?? null,
        'code'      => $r['code'] ?? null,
        'sku'       => $r['sku'] ?? null,
        'twelve_nc' => $r['twelve_nc'] ?? null,
        'barcode'   => $r['barcode'] ?? null,
        'price'     => $r['price'] !== null ? (float)$r['price'] : null,
    ];
}

json_out($out);
