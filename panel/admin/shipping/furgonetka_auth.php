<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$ownerId = $_SESSION['user']['owner_id'] ?? null;
if (!$ownerId) {
    die('Brak owner_id');
}

// Dane aplikacji z panelu Furgonetki
$clientId = 'olajpl-ca58fd55bbcd1740a7f9c326ae4942dc'; // wstaw z panelu
$redirectUri = 'https://panel.olaj.pl/api/furgonetka/oauth_callback.php';

$authUrl = 'https://furgonetka.pl/oauth/v2/auth?' . http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'ecommerce'
]);

header('Location: ' . $authUrl);
exit;
