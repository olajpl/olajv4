<?php
// bootstrap.php — centralny punkt wejścia
declare(strict_types=1);

$ROOT = __DIR__;

// Kolejność ważna: db → log → auth → helpers
require_once $ROOT . '/includes/db.php';
if (is_file($ROOT . '/includes/log.php')) {
    require_once $ROOT . '/includes/log.php';
}
if (is_file($ROOT . '/includes/auth.php')) {
    require_once $ROOT . '/includes/auth.php';
}
if (is_file($ROOT . '/includes/helpers.php')) {
    require_once $ROOT . '/includes/helpers.php';
}
