<?php
// admin/suppliers/store.php — zapis nowego dostawcy (Olaj.pl V4)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';
require_once __DIR__ . '/../../engine/Supplier/SupplierEngine.php';

use Engine\Supplier\SupplierEngine;

if (session_status() === PHP_SESSION_NONE) session_start();
$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($owner_id <= 0) {
    http_response_code(403);
    exit('❌ Brak dostępu.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: create.php');
    exit;
}

// CSRF
$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    logg('warning', 'suppliers.create', 'CSRF mismatch', ['owner_id' => $owner_id]);
    http_response_code(400);
    exit('❌ Błąd CSRF.');
}

$data = [
    'name'    => $_POST['name'] ?? '',
    'email'   => $_POST['email'] ?? '',
    'phone'   => $_POST['phone'] ?? '',
    'address' => $_POST['address'] ?? '',
    'note'    => $_POST['note'] ?? '',
    'box'     => $_POST['box'] ?? '',
];

try {
    $engine = new SupplierEngine($pdo);
    $id = $engine->create($owner_id, $data);
    logg('info', 'suppliers.create', 'supplier.created', ['owner_id' => $owner_id, 'supplier_id' => $id, 'name' => $data['name']]);

    // sukces → redirect na listę/edycję
    header('Location: view.php?id=' . (int)$id);
    exit;
} catch (Throwable $e) {
    logg('error', 'suppliers.create', 'supplier.create_failed', ['owner_id' => $owner_id, 'error' => $e->getMessage()]);
    http_response_code(400);
    echo '<div style="max-width:680px;margin:2rem auto;padding:1rem;border:1px solid #f00;background:#fee;">' .
        '❌ Nie udało się zapisać dostawcy: ' . htmlspecialchars($e->getMessage()) .
        ' <div><a href="create.php" style="color:#06c">Wróć</a></div></div>';
}
