<?php
// admin/products/update.php
declare(strict_types=1);

// 1) Autoryzacja / DB / Engine
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';
require_once __DIR__ . '/../../engine/Product/ProductEngine.php';

use Engine\Product\ProductEngine;

if (session_status() === PHP_SESSION_NONE) session_start();

// 2) Owner + CSRF
$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
if (!$owner_id) { http_response_code(403); exit('Brak właściciela.'); }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(403); exit('Błędny CSRF.');
}

// 3) Dane z formularza (mapowanie na pola silnika)
$id          = (int)($_POST['id'] ?? 0);
$name        = trim((string)($_POST['name'] ?? ''));
$code        = trim((string)($_POST['code'] ?? ''));
$category_id = (isset($_POST['category_id']) && $_POST['category_id'] !== '') ? (int)$_POST['category_id'] : null;
$active      = !empty($_POST['active']) ? 1 : 0;

// Obsłuż różne nazwy pól z formularza:
$price_in    = isset($_POST['unit_price']) ? (string)$_POST['unit_price'] : (string)($_POST['price'] ?? '0');
$vat_in      = (string)($_POST['vat_rate'] ?? '23');
$stock_in    = isset($_POST['stock_available']) ? (string)$_POST['stock_available'] : (string)($_POST['stock'] ?? '0');
$weight_in   = ($_POST['weight'] ?? '') === '' ? null : (float)$_POST['weight']; // w KG z UI
$tags        = array_map('intval', $_POST['tags'] ?? []);

if ($id <= 0 || $name === '' || $code === '') {
    $_SESSION['error_message'] = 'Brak wymaganych danych.';
    header('Location: edit.php?id=' . $id);
    exit;
}

// 4) Payload do silnika
$payload = [
    'name'            => $name,
    'code'            => $code,
    'unit_price'      => $price_in,
    'vat_rate'        => $vat_in,
    'category_id'     => $category_id,
    'active'          => $active,
    'stock_available' => (int)$stock_in, // ← to jest kluczowe: silnik zrobi korektę względem stock_cached
];
// waga tylko jeśli podana
if ($weight_in !== null) {
    $payload['weight'] = (float)$weight_in; // silnik wykryje weight_grams/weight
}

// 5) Zapis przez silnik + tagi + (opcjonalnie) zdjęcie
try {
    $engine = new ProductEngine($pdo, $owner_id);

    // a) update
    $engine->update($id, $payload);

    // b) tagi (opcjonalnie)
    if (!empty($tags)) {
        // obetnij do 100 na wszelki wypadek
        $tags = array_slice(array_unique(array_map('intval', $tags)), 0, 100);
        $engine->setTags($id, $tags);
    } else {
        // wyczyść tagi jeśli brak w POST
        $engine->setTags($id, []);
    }

    // c) upload zdjęcia – jeśli formularz wysyła plik o nazwie "image"
    if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $err = (int)($_FILES['image']['error'] ?? UPLOAD_ERR_OK);
        if ($err !== UPLOAD_ERR_OK) throw new \RuntimeException('Błąd uploadu (kod '.$err.').');

        $maxBytes = 8 * 1024 * 1024;
        if (($_FILES['image']['size'] ?? 0) > $maxBytes) throw new \RuntimeException('Plik zbyt duży (max 8 MB).');

        if (!class_exists('finfo')) throw new \RuntimeException('Brak rozszerzenia finfo.');
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string)$finfo->file($_FILES['image']['tmp_name']);
        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!isset($allowed[$mime])) throw new \RuntimeException('Nieobsługiwany typ obrazu.');

        [$w, $h] = @getimagesize($_FILES['image']['tmp_name']) ?: [0,0];
        if ($w < 20 || $h < 20) throw new \RuntimeException('Obraz zbyt mały/uszkodzony.');

        $ext   = $allowed[$mime];
        $fname = 'p'.$id.'_'.bin2hex(random_bytes(8)).'.'.$ext;

        $baseDir = realpath(__DIR__ . '/../../');
        if ($baseDir === false) throw new \RuntimeException('Ścieżka bazowa niedostępna.');
        $destDir = $baseDir . '/uploads/products';
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                throw new \RuntimeException('Nie można utworzyć katalogu upload.');
            }
        }
        $dest = $destDir . '/' . $fname;

        if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
            throw new \RuntimeException('Upload zdjęcia nie powiódł się.');
        }

        // oznacz jako główne
        $pdo->prepare("UPDATE product_images SET is_main=0 WHERE product_id=?")->execute([$id]);
        $pdo->prepare("INSERT INTO product_images (product_id, image_path, is_main, uploaded_at) VALUES (?, ?, 1, NOW())")
            ->execute([$id, $fname]);
    }

    $_SESSION['success_message'] = 'Zmiany zapisane.';
} catch (\Throwable $e) {
    log_exception($e, 'products_update');
    $_SESSION['error_message'] = 'Błąd zapisu: ' . $e->getMessage();
}

// 6) Powrót do edycji
header('Location: edit.php?id=' . $id);
