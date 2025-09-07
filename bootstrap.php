<?php
// bootstrap.php — centralny punkt wejścia (Olaj V4) — SAFE-LITE
declare(strict_types=1);

if (!defined('APP_START')) define('APP_START', microtime(true));
$ROOT = __DIR__;
if (!defined('APP_ROOT')) define('APP_ROOT', $ROOT);

/* === ENV loader (.env + .env.local) — nieinwazyjny === */
if (!isset($GLOBALS['__ENV_VARS'])) $GLOBALS['__ENV_VARS'] = [];
$__env_files = [$ROOT.'/.env', $ROOT.'/.env.local'];
foreach ($__env_files as $__f) {
    if (!is_file($__f)) continue;
    foreach (file($__f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        $v = preg_replace('/^([\'"])(.*)\\1$/', '$2', $v);
        if ($k === '') continue;
        $GLOBALS['__ENV_VARS'][$k] = $v;
        $_SERVER[$k] = $_ENV[$k]   = $v;
        putenv($k.'='.$v);
    }
}
/* helper env() tylko jeśli nie istnieje */
if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string {
        return $_SERVER[$key] ?? $_ENV[$key] ?? ($GLOBALS['__ENV_VARS'][$key] ?? $default);
    }
}

/* === JSON API guard (tylko dla endpointów API) — bez zmian === */
if (
    (\defined('OLAJ_JSON_API') && OLAJ_JSON_API === true)
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

/* === Request meta (request_id, owner_id hint) — bez zmian === */
if (!isset($GLOBALS['__olaj_request_id'])) {
    $GLOBALS['__olaj_request_id'] = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
}

/* === Includes (db, logger, auth, helpers) — kolejność bez zmian === */
require_once $ROOT . '/includes/db.php';
if (is_file($ROOT . '/includes/log.php'))    require_once $ROOT . '/includes/log.php';
if (is_file($ROOT . '/includes/auth.php'))   require_once $ROOT . '/includes/auth.php';
if (is_file($ROOT . '/includes/helpers.php'))require_once $ROOT . '/includes/helpers.php';

/* === DB Guard — jak było === */
if (is_file($ROOT . '/includes/db_guard.php')) require_once $ROOT . '/includes/db_guard.php';

/* === Autoloader (Engine\) — jak u Ciebie + miękki fallback na case === */
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Engine\\')) return;
    $rel  = str_replace('\\', '/', substr($class, 7)) . '.php';
    $base = APP_ROOT . '/engine/';
    $path = $base . $rel;
    if (is_file($path)) { require_once $path; return; }

    // fallback: kapitalizacja segmentów katalogów (tylko foldery), nie dotykamy pliku
    $segments = explode('/', $rel);
    foreach ($segments as $i => $seg) {
        if ($i < count($segments) - 1) $segments[$i] = ucfirst($seg);
    }
    $alt = $base . implode('/', $segments);
    if (is_file($alt)) { require_once $alt; return; }

    // fallback 2: wszystko małe (na wypadek legacy)
    $lower = $base . strtolower($rel);
    if (is_file($lower)) { require_once $lower; return; }
});

/* === owner_id hint dla loggera — bez zmian === */
if (session_status() === PHP_SESSION_ACTIVE) {
    $oid = $_SESSION['user']['owner_id'] ?? $_SESSION['owner_id'] ?? null;
    if ($oid) $GLOBALS['__olaj_owner_id'] = (int)$oid;
}

/* === AI/Ollama z ENV — tylko stałe (zero side-effectów) === */
if (!defined('OLLAMA_HOST'))     define('OLLAMA_HOST', env('OLLAMA_HOST', 'http://127.0.0.1:11434') ?? 'http://127.0.0.1:11434');
if (!defined('AI_DEFAULT_MODEL')) define('AI_DEFAULT_MODEL', env('AI_DEFAULT_MODEL', 'llama3:latest') ?? 'llama3:latest');

/* === log startu requestu — jak u Ciebie === */
if (function_exists('logg')) {
    logg('debug', 'bootstrap', 'request.start', [
        'request_id' => $GLOBALS['__olaj_request_id'] ?? null,
        'method'     => $_SERVER['REQUEST_METHOD'] ?? null,
        'uri'        => $_SERVER['REQUEST_URI'] ?? null,
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}
