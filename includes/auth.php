<?php
// includes/auth.php — hardened v2.1 (Olaj V4)

declare(strict_types=1);
// includes/auth.php (na samym początku, przed session_start)
if (session_status() !== PHP_SESSION_ACTIVE) {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',         // jeśli panel jest na subdomenie i chcesz współdzielić, ustaw np. '.olaj.pl'
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

// 0) CLI/worker? — nie dotykamy sesji i nagłówków
if (PHP_SAPI === 'cli') {
    // W CLI po prostu udostępniamy helpersy bez session/header zabawy.
    if (!function_exists('auth_user')) {
        function auth_user(): array { return $_SESSION['user'] ?? []; }
    }
    if (!function_exists('require_owner')) {
        function require_owner(): int {
            $oid = (int)($_SESSION['user']['owner_id'] ?? 0);
            if ($oid <= 0) { throw new RuntimeException('Owner context required'); }
            return $oid;
        }
    }
    return;
}

// 1) Auto-detekcja HTTPS (dla secure cookie)
$__is_https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
);

// 1a) Polifill str_starts_with (PHP 7.x) – użyj natywnej jeśli jest
if (!function_exists('str_starts_with_poly')) {
    if (function_exists('str_starts_with')) {
        function str_starts_with_poly(string $haystack, string $needle): bool {
            return str_starts_with($haystack, $needle);
        }
    } else {
        function str_starts_with_poly(string $haystack, string $needle): bool {
            return strncmp($haystack, $needle, strlen($needle)) === 0;
        }
    }
}

// 2) Start sesji TYLKO jeśli nie działa — PRZED jakimkolwiek outputem
if (session_status() === PHP_SESSION_NONE) {
    // Cookie params (PHP >= 7.3 ma sygnaturę tablicową)
    $cookieParams = [
        'lifetime' => 0,
        'path'     => '/',
        // 'domain'  => null, // pozostaw null/nie ustawiaj — PHP użyje bieżącej domeny
        'secure'   => $__is_https,  // przy SameSite=None MUSI być true
        'httponly' => true,
        'samesite' => 'Lax',        // dla panelu najlepszy kompromis
    ];
    if (function_exists('session_set_cookie_params')) {
        session_set_cookie_params($cookieParams);
    } else {
        // Fallback dla starszych PHP
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_httponly', '1');
        if ($__is_https) ini_set('session.cookie_secure', '1');
    }

    // Zabezpieczenie przed head’ers already sent
    if (headers_sent($hs_file, $hs_line)) {
        error_log("auth.php: headers already sent at {$hs_file}:{$hs_line}");
        // Nie wywalamy fatala – ale sesji już sensownie nie uruchomimy
    }

    @session_start();

    // Anti-fixation — tylko raz po świeżym starcie
    if (!isset($_SESSION['__initialized'])) {
        try { @session_regenerate_id(true); } catch (\Throwable $e) {}
        $_SESSION['__initialized'] = 1;
    }
}

// 3) Helpersy auth/role
if (!function_exists('auth_user')) {
    function auth_user(): array {
        return $_SESSION['user'] ?? [];
    }
}
if (!function_exists('auth_has_role')) {
    function auth_has_role($roles): bool {
        $u = auth_user();
        $role = strtolower($u['role'] ?? '');
        foreach ((array)$roles as $r) {
            if ($role === strtolower((string)$r)) return true;
        }
        return false;
    }
}
if (!function_exists('is_suadmin')) {
    function is_suadmin(): bool {
        $u = auth_user();
        $role = strtolower($u['role'] ?? '');
        if (in_array($role, ['superadmin','suadmin'], true)) return true;
        if (!empty($u['permissions']) && in_array('logs.manage', (array)$u['permissions'], true)) return true;
        return false;
    }
}
if (!function_exists('require_owner')) {
    function require_owner(): int {
        $oid = (int)($_SESSION['user']['owner_id'] ?? 0);
        if ($oid <= 0) {
            http_response_code(403);
            exit('Brak kontekstu właściciela.');
        }
        return $oid;
    }
}

// 4) Detekcja API/AJAX/fetch/JSON
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$ct     = $_SERVER['CONTENT_TYPE'] ?? '';
$xrq    = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
$path   = $_SERVER['REQUEST_URI'] ?? '';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// fetch() bywa bez Accept, więc patrzymy szerzej:
$isJsonishReq = (
    stripos($accept, 'application/json') !== false
    || stripos($ct, 'application/json') !== false
);

$isApiPath = (
    str_starts_with_poly($path, '/admin/api/')
    || str_starts_with_poly($path, '/api/')
);
$isAjax = (
    $isApiPath
    || $isJsonishReq
    || ($xrq === 'xmlhttprequest')
);

// 5) Preflight OPTIONS – odpuść auth, ale ustaw minimalne nagłówki
if ($method === 'OPTIONS') {
    if (!headers_sent()) {
        header('Access-Control-Allow-Origin: *'); // ew. zawęź do panelu
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 600');
        header('Content-Length: 0');
    }
    exit; // nic dalej
}

// 6) Wymuś zalogowanie / owner_id
$__user = $_SESSION['user'] ?? null;
$__owner_ok = !empty($__user['id']) && !empty($__user['owner_id']);

if (!$__owner_ok) {
    if ($isAjax) {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }
        echo json_encode(['success' => false, 'error' => 'AUTH_REQUIRED'], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        // klasyczny redirect na login (dla GET). Dla POST lepiej 303 See Other.
        $redirect = urlencode((string)($_SERVER['REQUEST_URI'] ?? '/'));
        if (!headers_sent()) {
            $code = ($method === 'GET') ? 302 : 303;
            header('Location: /auth/login.php?redirect=' . $redirect, true, $code);
        }
        exit;
    }
}

// 7) CSRF seed (na wszelki wypadek)
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// 8) Skróty (opcjonalnie, zgodnie z Waszym stylem)
$user_id   = (int)($_SESSION['user']['id'] ?? 0);
$owner_id  = (int)($_SESSION['user']['owner_id'] ?? 0);
$user_role = (string)($_SESSION['user']['role'] ?? 'user');

// 9) Security nagłówki (panel)
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    // Content-Security-Policy ustawiasz raczej na poziomie serwera / layoutu
}
