<?php
require_once __DIR__ . '/../../includes/db.php';
session_start();

$code = $_GET['code'] ?? null;
if (!$code) {
    die('Brak kodu autoryzacyjnego');
}

// Dane aplikacji
$clientId = 'olajpl-ca58fd55bbcd1740a7f9c326ae4942dc';
$clientSecret = 'dad7bb187c68b24dff86124a7f03e915c31effa51d78b3d7d98ca8f7b09f731d';
$redirectUri = 'https://panel.olaj.pl/api/furgonetka/oauth_callback.php';

// Wymiana code -> token
$ch = curl_init('https://api.furgonetka.pl/oauth/v2/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => $redirectUri,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die('Błąd podczas wymiany tokenu: ' . $response);
}

$data = json_decode($response, true);

$ownerId = $_SESSION['user']['owner_id'] ?? null;
if (!$ownerId) {
    die('Brak owner_id w sesji');
}

// Zapis tokenów
$stmt = $pdo->prepare("
    UPDATE shipping_integrations
    SET oauth_access_token = ?, oauth_refresh_token = ?, oauth_expires_at = ?
    WHERE owner_id = ? AND broker = 'furgonetka'
");
$stmt->execute([
    $data['access_token'],
    $data['refresh_token'],
    date('Y-m-d H:i:s', time() + $data['expires_in']),
    $ownerId
]);

echo "✅ Integracja z Furgonetką zakończona pomyślnie. Możesz zamknąć to okno.";
