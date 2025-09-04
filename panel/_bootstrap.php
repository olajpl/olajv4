<?php

/**
 * _bootstrap.php — wspólny bootstrap Olaj.pl V4
 * - PDO ($pdo)
 * - autoload klas z /engine
 * - sesja i CSRF
 * - env loader (plik .env w katalogu głównym)
 * - logger
 * - helpery JSON/abort
 * - funkcje tokenów Furgonetki kompatybilne z obecną integracją
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// 1) Stałe i podstawowa konfiguracja
// ─────────────────────────────────────────────────────────────────────────────
if (!defined('APP_START')) define('APP_START', microtime(true));

$__ROOT = __DIR__;               // katalog projektu (tam, gdzie leży ten plik)
$__ENV  = $__ROOT . '/.env';     // opcjonalny plik środowiskowy

// Bezpieczne ustawienia środowiska
date_default_timezone_set('Europe/Warsaw');
mb_internal_encoding('UTF-8');

// Tryb DEV/PROD z ENV (domyślnie PROD)
$__ENV_VARS = [];
if (is_file($__ENV)) {
    foreach (file($__ENV, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        // zdejmij otaczające cudzysłowy
        $v = preg_replace('/^([\'"])(.*)\\1$/', '$2', $v);
        $__ENV_VARS[$k] = $v;
        // też do $_SERVER/$_ENV — bywa wygodne
        $_SERVER[$k] = $_ENV[$k] = $v;
    }
}
function env(string $key, ?string $default = null): ?string
{
    return $_SERVER[$key] ?? $_ENV[$key] ?? $GLOBALS['__ENV_VARS'][$key] ?? $default;
}

$APP_ENV = strtolower(env('APP_ENV', 'prod') ?? 'prod');
$IS_DEV  = ($APP_ENV === 'dev' || $APP_ENV === 'development');

if ($IS_DEV) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
}

// ─────────────────────────────────────────────────────────────────────────────
// 2) Autoload (engine/* + vendor/autoload.php jeśli istnieje)
// ─────────────────────────────────────────────────────────────────────────────
$vendorAutoload = $__ROOT . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

// Prosty PSR-4 dla przestrzeni Engine\ -> /engine
spl_autoload_register(function ($class) use ($__ROOT) {
    $prefix = 'Engine\\';
    $baseDir = $__ROOT . '/engine/';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $rel = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) require_once $file;
});

// ─────────────────────────────────────────────────────────────────────────────
// 3) PDO (UTF-8, wyjątki, brak emulacji, persistent optional)
//    Zmiennie oczekiwane w .env: DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT
// ─────────────────────────────────────────────────────────────────────────────
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $dbHost = env('DB_HOST', '127.0.0.1');
    $dbPort = env('DB_PORT', '3306');
    $dbName = env('DB_NAME', '');
    $dbUser = env('DB_USER', '');
    $dbPass = env('DB_PASS', '');

    if ($dbName === '' || $dbUser === '') {
        // Minimalna diagnostyka — bez HTML
        http_response_code(500);
        echo 'Brak konfiguracji bazy (DB_NAME/DB_USER) w .env';
        exit;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);

    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => (bool)(int)env('DB_PERSISTENT', '0'),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// 4) Sesja + prosty CSRF
// ─────────────────────────────────────────────────────────────────────────────
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Lekkie utwardzenie sesji
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
// kompatybilność z różnymi widokami: czasem patrzą na csrf_token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $_SESSION['csrf'];
}

function csrf_token(): string
{
    return (string)($_SESSION['csrf'] ?? '');
}
function csrf_verify(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals((string)($_SESSION['csrf'] ?? ''), $token);
}

// ─────────────────────────────────────────────────────────────────────────────
// 5) Logger (PSR-0 style, ale prosty) + short-hands
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('olaj_v4_log')) {
    function olaj_v4_log(string $tag, array $ctx = []): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] {$tag} " . json_encode($ctx, JSON_UNESCAPED_UNICODE);
        error_log($line);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 6) Helpery odpowiedzi HTTP
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('jout')) {
    function jout(mixed $data, int $code = 200): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!function_exists('abort')) {
    function abort(int $code, string $message = 'Error'): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $message;
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 7) Funkcje tokenów Furgonetki (zgodne z Twoją integracją)
//    Tabela: shipping_integrations (kolumny oauth_* + owner_id, broker='furgonetka')
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Główna — zwraca access_token dla danego ownera.
 * Zwraca pusty string, jeśli brak rekordu/klucza.
 */
function getFurgonetkaTokenForOwner(PDO $pdo, int $ownerId): string
{
    $st = $pdo->prepare("
        SELECT oauth_access_token, oauth_expires_at
        FROM shipping_integrations
        WHERE owner_id = :oid AND broker = 'furgonetka'
        LIMIT 1
    ");
    $st->execute([':oid' => $ownerId]);
    $row = $st->fetch();
    if (!$row) return '';
    $token = (string)($row['oauth_access_token'] ?? '');
    return $token;
}

/**
 * Odświeżenie tokenu — tu wstaw swój realny flow OAuth (client_id/secret/refresh_token).
 * Poniżej szkic: jeśli masz refresh_token i endpoint OAuth Furgonetki, wywołaj go tutaj,
 * po czym zaktualizuj rekord w DB i zwróć nowy access_token.
 */
function refreshFurgonetkaTokenForOwner(PDO $pdo, int $ownerId): string
{
    // szkic; zostawiam „hard-fail”, żeby zauważyć, jeśli nie podmienisz:
    olaj_v4_log('furgonetka.token.refresh.missing', ['owner_id' => $ownerId]);
    return getFurgonetkaTokenForOwner($pdo, $ownerId); // awaryjnie zwróć stary (może jeszcze żyje)
}

/**
 * Wersje kompatybilne z „uniwersalną integracją”, która wołała:
 *   getFurgonetkaToken($pdo, $ownerId)
 *   refreshFurgonetkaToken($pdo, $ownerId)
 * oraz czasem bez argumentów (mniej preferowane).
 */
function getFurgonetkaToken(PDO $pdoOrNull = null, ?int $ownerId = null): string
{
    $pdo = $pdoOrNull instanceof PDO ? $pdoOrNull : ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) return '';
    if ($ownerId === null) {
        // spróbuj z kontekstu sesji admina
        $ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
        if ($ownerId <= 0) return '';
    }
    return getFurgonetkaTokenForOwner($pdo, $ownerId);
}
function refreshFurgonetkaToken(PDO $pdoOrNull = null, ?int $ownerId = null): string
{
    $pdo = $pdoOrNull instanceof PDO ? $pdoOrNull : ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) return '';
    if ($ownerId === null) {
        $ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
        if ($ownerId <= 0) return '';
    }
    return refreshFurgonetkaTokenForOwner($pdo, $ownerId);
}

// ─────────────────────────────────────────────────────────────────────────────
// 8) Flagi kontekstu wywołania (pomocnicze)
// ─────────────────────────────────────────────────────────────────────────────
if (!defined('IS_CLI'))  define('IS_CLI', PHP_SAPI === 'cli');
if (!defined('APP_ROOT')) define('APP_ROOT', $__ROOT);

// wszystko gotowe: $pdo, autoload, logger, CSRF, env, helpery
