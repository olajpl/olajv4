<?php
// admin/products/api/product_update.php — zapisywanie produktu (Olaj V4)
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';
require_once __DIR__ . '/../../../engine/Product/ProductEngine.php';

use Engine\Product\ProductEngine;

if (session_status() === PHP_SESSION_NONE) session_start();

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$user_id  = (int)($_SESSION['user']['id'] ?? 0);
if ($owner_id <= 0 || $user_id <= 0) {
    http_response_code(401);
    exit('unauthorized');
}

$csrf_form = (string)($_POST['csrf_token'] ?? '');
$csrf_sess = (string)($_SESSION['csrf_token'] ?? '');
if ($csrf_form === '' || $csrf_sess === '' || !hash_equals($csrf_sess, $csrf_form)) {
    http_response_code(403);
    exit('csrf_fail');
}

function hredirect(int $id): void
{
    header('Location: /admin/products/edit.php?id=' . $id);
    exit;
}
function toDecimal($v): float
{
    $s = str_replace([' ', ','], ['', '.'], (string)$v);
    return round((float)$s, 2);
}
function tableExists(PDO $pdo, string $name): bool
{
    $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
    $st->execute([':t' => $name]);
    return (bool)$st->fetchColumn();
}
function columnExists(PDO $pdo, string $t, string $c): bool
{
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1");
    $st->execute([':t' => $t, ':c' => $c]);
    return (bool)$st->fetchColumn();
}

$product_id     = (int)($_POST['id'] ?? 0);
$name           = trim((string)($_POST['name'] ?? ''));
$code           = trim((string)($_POST['code'] ?? ''));
$unit_price_in  = $_POST['unit_price'] ?? null;
$vat_rate_in    = $_POST['vat_rate'] ?? null;
$category_id_in = $_POST['category_id'] ?? null;
$status_in      = (string)($_POST['status'] ?? 'active');
$available_from = (string)($_POST['available_from'] ?? '');
$tags_in        = $_POST['tags'] ?? [];
$description_in = (string)($_POST['description'] ?? ''); // NOWE

$weight_ui_kg   = $_POST['weight_kg_ui'] ?? null;
$weight_grams   = $_POST['weight_grams'] ?? null;

if ($product_id <= 0) {
    http_response_code(400);
    exit('bad_product');
}
if ($name === '' || $code === '') {
    $_SESSION['error_message'] = 'Uzupełnij nazwę i kod.';
    hredirect($product_id);
}

$active = 1;
$doDelete = false;
switch ($status_in) {
    case 'active':
        $active = 1;
        break;
    case 'inactive':
    case 'draft':
        $active = 0;
        break;
    case 'deleted':
        $doDelete = true;
        break;
}

$unit_price = $unit_price_in !== null && $unit_price_in !== '' ? toDecimal($unit_price_in) : 0.00;
$vat_rate   = $vat_rate_in   !== null && $vat_rate_in   !== '' ? toDecimal($vat_rate_in)   : 23.00;
$category_id = ($category_id_in !== null && $category_id_in !== '') ? (int)$category_id_in : null;

$weight_kg = null;
if ($weight_ui_kg !== null && $weight_ui_kg !== '')      $weight_kg = (float)str_replace(',', '.', (string)$weight_ui_kg);
elseif ($weight_grams !== null && $weight_grams !== '')   $weight_kg = ((float)$weight_grams) / 1000.0;

try {
    $engine = new ProductEngine($pdo, $owner_id);

    if ($doDelete) {
        $engine->delete($product_id);
        $_SESSION['success_message'] = 'Produkt oznaczony jako usunięty.';
        header('Location: /admin/products/index.php');
        exit;
    }

    // update podstawowych pól
    $payload = [
        'name'           => $name,
        'code'           => $code,
        'unit_price'     => $unit_price,
        'vat_rate'       => $vat_rate,
        'category_id'    => $category_id,
        'active'         => $active,
        'available_from' => $available_from !== '' ? $available_from : null,
    ];
    if ($weight_kg !== null) $payload['weight'] = $weight_kg;

    $engine->update($product_id, $payload);

    // zapis checkboxów tagów (jeśli są)
    $tagIds = [];
    if (is_array($tags_in)) {
        foreach ($tags_in as $t) {
            $iv = (int)$t;
            if ($iv > 0) $tagIds[] = $iv;
        }
    }
    $engine->setTags($product_id, $tagIds);

    // ZAPIS OPISU: product_descriptions (preferowane) lub kolumna products.description (jeśli istnieje)
    if (tableExists($pdo, 'product_descriptions')) {
        // rekomendowane: unique (product_id, owner_id)
        $pdo->prepare("
            INSERT INTO product_descriptions (product_id, owner_id, content, generated_at)
            VALUES (:pid, :oid, :c, NOW())
            ON DUPLICATE KEY UPDATE content = VALUES(content), generated_at = NOW()
        ")->execute([
            ':pid' => $product_id,
            ':oid' => $owner_id,
            ':c' => $description_in
        ]);
    } elseif (columnExists($pdo, 'products', 'description')) {
        $pdo->prepare("UPDATE products SET description=:c, updated_at=NOW() WHERE id=:pid AND owner_id=:oid")
            ->execute([':c' => $description_in, ':pid' => $product_id, ':oid' => $owner_id]);
    }
    // (jeśli nie ma żadnego z powyższych – opis po prostu pomijamy bez błędu)

    $_SESSION['success_message'] = '✔ Zapisano zmiany produktu.';
    hredirect($product_id);
} catch (Throwable $e) {
    logg('error', 'products.update', 'exception', ['product_id' => $product_id, 'owner_id' => $owner_id, 'error' => $e->getMessage()]);
    $_SESSION['error_message'] = '❌ Błąd zapisu: ' . $e->getMessage();
    hredirect($product_id);
}
