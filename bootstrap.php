<?php
// bootstrap.php — centralny punkt wejścia (Olaj V4)
declare(strict_types=1);

$ROOT = __DIR__;

/* === JSON API guard (tylko dla endpointów API) === */
if (
    \defined('OLAJ_JSON_API') && OLAJ_JSON_API === true
    || (!empty($_SERVER['HTTP_X_JSON']) && $_SERVER['HTTP_X_JSON'] === '1')
    || (!empty($_GET['format']) && $_GET['format'] === 'json')
    || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
        && (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'))
) {
    $guard = $ROOT . '/includes/json_api_guard.php';
    if (is_file($guard)) {
        require_once $guard;
    }
}

/* === Request meta (request_id, owner_id hint) === */
if (!isset($GLOBALS['__olaj_request_id'])) {
    $GLOBALS['__olaj_request_id'] = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
}

/* === Includes (db, logger, auth, helpers) === */
require_once $ROOT . '/includes/db.php';
if (is_file($ROOT . '/includes/log.php')) require_once $ROOT . '/includes/log.php';
if (is_file($ROOT . '/includes/auth.php')) require_once $ROOT . '/includes/auth.php';
if (is_file($ROOT . '/includes/helpers.php')) require_once $ROOT . '/includes/helpers.php';

/* === DB Guard === */
if (is_file($ROOT . '/includes/db_guard.php')) require_once $ROOT . '/includes/db_guard.php';

/* === Autoloader === */
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Engine\\')) {
        $path = __DIR__ . '/engine/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($path)) require_once $path;
    }
});

/* === owner_id hint dla loggera === */
if (session_status() === PHP_SESSION_ACTIVE) {
    $oid = $_SESSION['user']['owner_id'] ?? $_SESSION['owner_id'] ?? null;
    if ($oid) $GLOBALS['__olaj_owner_id'] = (int)$oid;
}

/* === log startu requestu === */
if (function_exists('logg')) {
    logg('debug', 'bootstrap', 'request.start', [
        'request_id' => $GLOBALS['__olaj_request_id'] ?? null,
        'method'     => $_SERVER['REQUEST_METHOD'] ?? null,
        'uri'        => $_SERVER['REQUEST_URI'] ?? null,
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}
