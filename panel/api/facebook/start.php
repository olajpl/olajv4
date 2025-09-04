<?php
// api/facebook/start.php
// 1. opis czynności lub funkcji
// Start integracji: buduje URL do logowania w FB i robi redirect z proper state/redirect_uri/scopes.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/log.php';
require_once __DIR__ . '/../../includes/env.php';
$appId     = env('FB_APP_ID');
$appSecret = env('FB_APP_SECRET');
$redirect  = env('FB_REDIRECT_URI') ?: 'https://panel.olaj.pl/api/facebook/connect.php';
if (empty($_SESSION['user']['owner_id'])) {
    http_response_code(403);
    echo 'Brak owner_id w sesji.';
    exit;
}

$redirect  = getenv('FB_REDIRECT_URI') ?: 'https://panel.olaj.pl/api/facebook/connect.php';
$appId     = getenv('FB_APP_ID');
$appSecret = getenv('FB_APP_SECRET');


if (!$appId || !$appSecret) {
    echo "❌ Brak FB_APP_ID/FB_APP_SECRET w env.";
    exit;
}

// 2) Zrób CSRF token i state
$csrf = bin2hex(random_bytes(12));
$_SESSION['fb_csrf'] = $csrf;
$state = json_encode(['csrf' => $csrf, 'owner_id' => $owner_id]);

// 3) redirect_uri MUSI być dokładnie taki jak w ustawieniach appki FB
$redirect = 'https://' . $_SERVER['HTTP_HOST'] . '/api/facebook/connect.php';

// 4) Zakresy do stron i wiadomości (dopasuj wg potrzeb)
$scopes = [
    'pages_show_list',
    'pages_read_engagement',
    'pages_manage_metadata',
    'pages_messaging',
    'pages_manage_posts',
];

$params = [
    'client_id'     => $appId,
    'redirect_uri'  => $redirect,
    'state'         => base64_encode($state),
    'scope'         => implode(',', $scopes),
    'response_type' => 'code',
];

$url = 'https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query($params);

logg('info', 'facebook.start', '➡️ Redirect to FB OAuth', ['owner_id' => $owner_id, 'redirect' => $redirect]);

header('Location: ' . $url);
exit;
