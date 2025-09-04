<?php
// konto/logout.php — wylogowanie klienta (V4, hard session + clean cookies)

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/log.php'; // olaj_v4_logger

// Pobierz dane klienta przed zniszczeniem sesji (do logów)
$clientId    = (int)($_SESSION['client']['id'] ?? 0);
$clientToken = (string)($_SESSION['client']['token'] ?? '');
$ownerId     = (int)($_SESSION['owner_id'] ?? 1);

// Czyść sesję
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

// Skasuj client_token identycznie jak był ustawiany
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

setcookie('client_token', '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'domain'   => '',        // lub '.olaj.pl' jeśli tak ustawiasz w setClientAuth()
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// Usuń też cart_sid (gościnny koszyk)
setcookie('cart_sid', '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// Logowanie zdarzenia
logg('info', 'auth.logout', 'client_logged_out', [
    'ownerId'     => $ownerId,
    'clientId'    => $clientId,
    'clientToken' => $clientToken,
    'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua'          => $_SERVER['HTTP_USER_AGENT'] ?? '',
]);

// Redirect na stronę główną
header('Location: /');
exit;
