<?php
// Plik: api/furgonetka/debug_webhook.php

$timestamp = date('Y-m-d_H-i-s');
$basePath = __DIR__ . '/logs';
if (!is_dir($basePath)) mkdir($basePath, 0777, true);

// Zapisz nagłówki
$headers = getallheaders();
file_put_contents("$basePath/{$timestamp}_headers.log", print_r($headers, true));

// Zapisz body
$body = file_get_contents('php://input');
file_put_contents("$basePath/{$timestamp}_body.json", $body);

// Zapisz _GET i _SERVER
file_put_contents("$basePath/{$timestamp}_meta.log", print_r([
    '_GET' => $_GET,
    '_SERVER' => $_SERVER
], true));

http_response_code(200);
echo json_encode(['status' => 'OK – dane zapisane do logs/']);
