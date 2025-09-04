<?php
// admin/products/api/image_upload.php — upload obrazka głównego produktu (Olaj.pl V4)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';
require_once __DIR__ . '/../../../engine/Product/ProductEngine.php';

use Engine\Product\ProductEngine;

if (session_status() === PHP_SESSION_NONE) session_start();

$owner_id   = (int)($_SESSION['user']['owner_id'] ?? 0);
$user_id    = (int)($_SESSION['user']['id'] ?? 0);
$csrf       = (string)($_POST['csrf_token'] ?? '');
$product_id = (int)($_POST['product_id'] ?? 0);
$mode       = (string)($_POST['mode'] ?? 'main'); // 'main' | 'gallery'

if ($owner_id <= 0 || $user_id <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}
if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf_fail']);
    exit;
}
if ($product_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_product']);
    exit;
}
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no_file']);
    exit;
}

$file = $_FILES['file'];

// Walidacja MIME/rozmiaru
$allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
$mime = (string)($file['type'] ?? '');
$ext  = $allowed[$mime] ?? null;
if (!$ext) {
    // fallback: spróbuj przez finfo
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$fi->file($file['tmp_name']);
    $ext  = $allowed[$mime] ?? null;
}
if (!$ext) {
    echo json_encode(['ok' => false, 'error' => 'bad_type']);
    exit;
}

$size = (int)$file['size'];
if ($size <= 0 || $size > 8 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'too_big']);
    exit;
}

// Ścieżki docelowe (PER-PRODUCT: /uploads/products/{product_id}/)
$uploadsRoot = realpath(__DIR__ . '/../../../uploads');
if ($uploadsRoot === false) {
    @mkdir(__DIR__ . '/../../../uploads', 0775, true);
    $uploadsRoot = realpath(__DIR__ . '/../../../uploads');
}
if ($uploadsRoot === false) {
    echo json_encode(['ok' => false, 'error' => 'mkdir_uploads_root']);
    exit;
}

$productDir = $uploadsRoot . '/products/' . $product_id;
if (!is_dir($productDir) && !mkdir($productDir, 0775, true) && !is_dir($productDir)) {
    echo json_encode(['ok' => false, 'error' => 'mkdir_product']);
    exit;
}

// Nazwa pliku
$basename = 'p' . $product_id . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
$destAbs  = $productDir . '/' . $basename;

// Zapis pliku
if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
    echo json_encode(['ok' => false, 'error' => 'move_fail']);
    exit;
}
resizeImage($destAbs, $destAbs, 800);
// Resize – max 800x800
function resizeImage(string $srcPath, string $destPath, int $maxDim = 800): bool
{
    $info = getimagesize($srcPath);
    if (!$info) return false;

    [$width, $height] = $info;
    $type = $info[2]; // IMAGETYPE_...

    $ratio = min($maxDim / $width, $maxDim / $height, 1);
    $newW = (int)($width * $ratio);
    $newH = (int)($height * $ratio);

    switch ($type) {
        case IMAGETYPE_JPEG:
            $src = imagecreatefromjpeg($srcPath);
            break;
        case IMAGETYPE_PNG:
            $src = imagecreatefrompng($srcPath);
            break;
        case IMAGETYPE_WEBP:
            $src = imagecreatefromwebp($srcPath);
            break;
        default:
            return false;
    }

    $dst = imagecreatetruecolor($newW, $newH);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);

    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($dst, $destPath, 90);
            break;
        case IMAGETYPE_PNG:
            imagepng($dst, $destPath);
            break;
        case IMAGETYPE_WEBP:
            imagewebp($dst, $destPath, 90);
            break;
        default:
            return false;
    }

    return true;
}

// Ścieżka relatywna względem /uploads (trzymana w DB)
$relative = 'products/' . $product_id . '/' . $basename;


try {
    $engine = new ProductEngine($pdo, $owner_id);

    if ($mode === 'gallery') {
        // w przyszłości: dopisz do galerii
        $engine->setGalleryImages($product_id, [$relative]);
    } else {
        // domyślnie: ustaw jako główny
        $engine->setMainImage($product_id, $relative);
    }
    echo json_encode(['ok' => true, 'path' => $relative, 'url' => '/uploads/' . $relative], JSON_UNESCAPED_UNICODE);
    exit;
    logg('info', 'products.image', 'upload.ok', [
        'product_id' => $product_id,
        'owner_id' => $owner_id,
        'user_id' => $user_id,
        'mode' => $mode,
        'file' => $relative,
        'size' => $size,
        'mime' => $mime
    ]);

    echo json_encode(['ok' => true, 'path' => $relative]);
} catch (Throwable $e) {
    logg('error', 'products.image', 'upload.fail', [
        'product_id' => $product_id,
        'owner_id' => $owner_id,
        'user_id' => $user_id,
        'error' => $e->getMessage()
    ]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'engine_fail', 'detail' => $e->getMessage()]);
}
