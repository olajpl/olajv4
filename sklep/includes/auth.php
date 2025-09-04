<?php
// includes/auth.php — trwałe logowanie po "client_token" w cookie + helpery
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Zapisz autoryzację klienta do sesji + odśwież trwałe cookie (30 dni).
 */
function setClientAuth(array $client, bool $persist = true): void
{
    $token = (string)$client['token'];
    $_SESSION['client_id']    = (int)$client['id'];
    $_SESSION['client_token'] = $token;
    $_SESSION['owner_id']     = (int)$client['owner_id'];

    // Migracja koszyka gościa
    if (!empty($_SESSION['cart_sid'])) {
        migrateCartToClient($GLOBALS['pdo'], $_SESSION['owner_id'], $_SESSION['cart_sid'], $token);
    }

    // Cookie 30 dni
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    if ($persist) {
        setcookie('client_token', $token, [
            'expires'  => time() + 60 * 60 * 24 * 30,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}


/**
 * Auto-logowanie: jeśli brak sesji, a jest client_token w cookie → wciągnij klienta z bazy.
 * Zwraca tablicę klienta albo null.
 */
function autoLoginFromCookie(): ?array
{
    if (!empty($_SESSION['client_id']) && !empty($_SESSION['client_token'])) {
        return [
            'id'       => (int)$_SESSION['client_id'],
            'token'    => (string)$_SESSION['client_token'],
            'owner_id' => (int)($_SESSION['owner_id'] ?? 1),
        ];
    }

    $cookieTok = $_COOKIE['client_token'] ?? '';
    if (!$cookieTok || !is_string($cookieTok)) return null;

    $client = getClientByToken($cookieTok);
    if (!$client) return null;

    setClientAuth($client);
    return $client;
}

/**
 * Wylogowanie: czyści sesję i ciasteczko.
 */
function logoutClient(): void
{
    // Usuń client_token cookie
    setcookie('client_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Czyść sesję
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}
