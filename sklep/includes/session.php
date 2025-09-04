<?php

declare(strict_types=1);

// Ustawienia sesji tylko jeśli SESJA JESZCZE NIE DZIAŁA
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Te ini_set MUSZĄ być PRZED session_start()
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '', // zostaw puste jeśli nie wymuszasz domeny
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    // Regenerate ID tylko gdy nagłówki nie wysłane i raz na jakiś czas
    if (!headers_sent()) {
        if (empty($_SESSION['_sid_regenerated_at']) || (time() - (int)$_SESSION['_sid_regenerated_at']) > 300) {
            session_regenerate_id(true);
            $_SESSION['_sid_regenerated_at'] = time();
        }
    }
}
