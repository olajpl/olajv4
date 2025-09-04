<?php
// /api/_probe_webhook_fb.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// URL do Twojego endpointu:
$endpoint = dirname(__FILE__) . '/webhook_fb.php';
$endpointUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']
    . rtrim(dirname($_SERVER['REQUEST_URI']), '/\\') . '/webhook_fb.php';

// Minimalny event "page"->"entry"->"messaging"
$body = [
    'object' => 'page',
    'entry' => [[
        'id' => 'TEST_PAGE',
        'time' => time(),
        'messaging' => [[
            'sender'   => ['id' => 'TEST_PSID'],
            'recipient' => ['id' => 'TEST_PAGE'],
            'timestamp' => time() * 1000,
            'message'  => [
                'mid'  => 'm_' . bin2hex(random_bytes(6)),
                'text' => 'daj 123 x2'
            ]
        ]]
    ]]
];

$ch = curl_init($endpointUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10
]);
$out = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo json_encode(['http' => $http, 'response' => $out, 'error' => $err], JSON_UNESCAPED_UNICODE);
