<?php
declare(strict_types=1);

// CLI: php tools/furgo_test_create.php --owner=1 --service_id=XXX --sandbox=0
require_once __DIR__ . '/../../api/furgonetka/furgonetka_api.php';
require_once __DIR__ . '/../../includes/shipping_furgonetka.php';

$opts = getopt('', ['owner::','service_id::','sandbox::']);
$ownerId   = (int)($opts['owner'] ?? 1);
$serviceId = (int)($opts['service_id'] ?? 0);
$sandbox   = (int)($opts['sandbox'] ?? 0);

// PDO:
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) { fwrite(STDERR, "Brak PDO\n"); exit(1); }

$api = new FurgonetkaAPI([
    'owner_id'        => $ownerId,
    'token_provider'  => fn() => getFurgonetkaToken($pdo, $ownerId),
    'token_refresher' => fn() => refreshFurgonetkaToken($pdo, $ownerId),
    'sandbox'         => (bool)$sandbox,
    'prefer'          => 'rest',
    'logger'          => 'ship_log',
]);

if ($serviceId <= 0) {
    // spróbujmy pierwszy dostępny
    $serviceId = furgo_resolve_service_id($api, null, null);
}

$pickup = [
    'name'         => 'Sklep XYZ',
    'company'      => 'Sklep XYZ',
    'street'       => 'Ulica 1',
    'postcode'     => '00-000',
    'city'         => 'Warszawa',
    'country_code' => 'PL',
    'email'        => 'sklep@example.com',
    'phone'        => '500600700',
];

$receiver = [
    'name'         => 'Jan Kowalski',
    'street'       => 'Testowa 1',
    'postcode'     => '00-001',
    'city'         => 'Warszawa',
    'country_code' => 'PL',
    'email'        => 'jan@example.com',
    'phone'        => '600700800',
];

$parcels = [[ 'weight'=>1.2, 'width'=>20, 'height'=>10, 'length'=>30 ]];

$payload = furgo_build_rest_payload($pickup, $receiver, $serviceId, $parcels, [
    'user_reference_number' => 'Test-CLI-'.time(),
]);

try {
    $res = $api->createShipment($payload, (string)time());
    echo "OK\n";
    echo json_encode($res, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    echo "Błąd: ".$e->getMessage()."\n";
    exit(1);
}
