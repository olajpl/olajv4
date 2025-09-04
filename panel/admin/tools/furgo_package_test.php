<?php
// admin/tools/furgo_package_test.php
// 🔹 Test utworzenia paczki przez Furgonetka REST API
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "ℹ️ Testuję Furgonetka REST: POST /packages …\n";

// Funkcja do wywołań API
function furgo_request(string $method, string $path, array $data = null, ?string $token = null) {
    $url = "https://api.furgonetka.pl" . $path;
    $ch = curl_init($url);

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json'
    ];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false) {
        throw new RuntimeException("cURL error: " . curl_error($ch));
    }
    curl_close($ch);

    return [$status, json_decode($resp, true)];
}

// 1. Pobierz token z bazy
$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($owner_id <= 0) {
    echo "❌ Brak owner_id w sesji.\n";
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM shipping_integrations WHERE owner_id = ? AND broker = 'furgonetka' LIMIT 1");
$stmt->execute([$owner_id]);
$cfg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cfg) {
    echo "❌ Brak konfiguracji Furgonetki w shipping_integrations.\n";
    exit;
}

$token = $cfg['access_token'];

// 2. Payload paczki testowej
$payload = [
    "service"    => "dpd",
    "service_id" => 35251242,
    "type"       => "package",

    "pickup" => [
        "name"     => "Sklep Olaj.pl",
        "email"    => "sklep@example.com",
        "phone"    => "500600700",
        "street"   => "Magazynowa 10",
        "postcode" => "00-950",
        "city"     => "Warszawa",
        "country"  => "PL"
    ],

    "receiver" => [
        "name"     => "Jan Kowalski",
        "email"    => "jan@example.com",
        "phone"    => "600600600",
        "street"   => "Testowa 1",
        "postcode" => "00-001",
        "city"     => "Warszawa",
        "country"  => "PL"
    ],

    "parcels" => [
        [
            "weight" => 1.0,
            "width"  => 20,
            "height" => 10,
            "depth"  => 30
        ]
    ]
];



// 3. Strzał
try {
    [$status, $json] = furgo_request("POST", "/packages", $payload, $token);

    if ($status >= 200 && $status < 300) {
        echo "✅ API OK — utworzono paczkę:\n";
        print_r($json);
    } else {
        echo "❌ API FAIL ($status):\n";
        print_r($json);
    }
} catch (Throwable $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
}
