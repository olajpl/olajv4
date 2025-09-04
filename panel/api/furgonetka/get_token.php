<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Dane logowania do API Furgonetki
$clientId     = 'olajpl-ca58fd55bbcd1740a7f9c326ae4942dc';
$clientSecret = 'dad7bb187c68b24dff86124a7f03e915c31effa51d78b3d7d98ca8f7b09f731d';
$username     = 'borowik.marek@gmail.com';
$password     = 'OldSch00l01!';

// Authorization header Basic base64(clientId:clientSecret)
$authHeader = base64_encode($clientId . ':' . $clientSecret);

$postFields = http_build_query([
    'grant_type' => 'password',
    'scope'      => 'api',
    'username'   => $username,
    'password'   => $password,
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
    echo "<pre>Błąd pobierania tokena:\n";
    echo "HTTP $httpCode\n";
    print_r($data);
    echo "</pre>";
    exit;
}

// Dane tokena
$accessToken  = $data['access_token'];
$refreshToken = $data['refresh_token'];
$expiresIn    = $data['expires_in']; // sekundy
$expiresAt    = date('Y-m-d H:i:s', time() + $expiresIn);

// Zapis do bazy – broker = 'furgonetka'
$stmt = $pdo->prepare("
    INSERT INTO shipping_integrations (owner_id, broker, oauth_access_token, oauth_refresh_token, oauth_expires_at)
    VALUES (:owner_id, 'furgonetka', :access_token, :refresh_token, :expires_at)
    ON DUPLICATE KEY UPDATE 
        oauth_access_token = VALUES(oauth_access_token),
        oauth_refresh_token = VALUES(oauth_refresh_token),
        oauth_expires_at = VALUES(oauth_expires_at)
");
$stmt->execute([
    'owner_id'      => $_SESSION['user']['owner_id'],
    'access_token'  => $accessToken,
    'refresh_token' => $refreshToken,
    'expires_at'    => $expiresAt,
]);

echo "<h2>✅ Token Furgonetki został zapisany!</h2>";
echo "<p>Ważny do: {$expiresAt}</p>";
