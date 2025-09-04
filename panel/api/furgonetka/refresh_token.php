<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Pobierz dane integracji Furgonetki
$stmt = $pdo->prepare("
    SELECT * FROM shipping_integrations 
    WHERE owner_id = :owner_id AND broker = 'furgonetka'
");
$stmt->execute(['owner_id' => $_SESSION['user']['owner_id']]);
$integration = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$integration) {
    die("❌ Brak zapisanej integracji Furgonetki – uruchom najpierw get_token.php");
}

$clientId     = 'TWOJ_CLIENT_ID';
$clientSecret = 'TWOJ_CLIENT_SECRET';
$refreshToken = $integration['oauth_refresh_token'] ?? '';

if (!$refreshToken) {
    die("❌ Brak zapisanego refresh_token – uruchom ponownie get_token.php");
}

$authHeader = base64_encode($clientId . ':' . $clientSecret);

$postFields = http_build_query([
    'grant_type'    => 'refresh_token',
    'refresh_token' => $refreshToken,
]);

$ch = curl_init('https://api.furgonetka.pl/oauth/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Basic ' . $authHeader,
    'Content-Type: application/x-www-form-urlencoded',
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (curl_errno($ch)) {
    die('Błąd CURL: ' . curl_error($ch));
}
curl_close($ch);

$data = json_decode($response, true);

if ($httpCode !== 200 || !isset($data['access_token'])) {
    echo "<pre>Błąd odświeżania tokena:\n";
    echo "HTTP $httpCode\n";
    print_r($data);
    echo "</pre>";
    exit;
}

$accessToken  = $data['access_token'];
$newRefresh   = $data['refresh_token'] ?? $refreshToken;
$expiresIn    = $data['expires_in'];
$expiresAt    = date('Y-m-d H:i:s', time() + $expiresIn);

// Aktualizacja w bazie
$stmt = $pdo->prepare("
    UPDATE shipping_integrations
    SET oauth_access_token = :access_token,
        oauth_refresh_token = :refresh_token,
        oauth_expires_at = :expires_at
    WHERE owner_id = :owner_id AND broker = 'furgonetka'
");
$stmt->execute([
    'access_token'  => $accessToken,
    'refresh_token' => $newRefresh,
    'expires_at'    => $expiresAt,
    'owner_id'      => $_SESSION['user']['owner_id']
]);

echo "<h2>✅ Token Furgonetki został odświeżony!</h2>";
echo "<p>Ważny do: {$expiresAt}</p>";
