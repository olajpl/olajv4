<?php
// admin/products/edit.php — Panel edycji produktu z insightami (Olaj.pl V4)
declare(strict_types=1);

// ✅ Używamy GLOBALNYCH include'ów (dwa poziomy wyżej)
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';
require_once __DIR__ . '/../../../engine/Product/ProductEngine.php';

use Engine\Product\ProductEngine;
use Engine\Log\LogEngine;

// Sesja (gdyby auth.php jej nie wystartował)
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];
