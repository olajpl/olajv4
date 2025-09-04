<?php
require_once __DIR__ . '/includes/db.php'; // jeśli masz taki plik
require_once __DIR__ . '/engine/parser/MessageParser.php'; // jeśli masz taki plik
require_once __DIR__ . '/engine/orders/OrderEngine.php'; // jeśli masz taki plik
require_once __DIR__ . '/engine/orders/ClientEngine.php'; // jeśli masz taki plik
require_once __DIR__ . '/engine/orders/ProductEngine.php'; // jeśli masz taki plik
require_once __DIR__ . '/engine/orders/PaymentEngine.php'; // jeśli masz taki plik



use Engine\Parser\MessageParser;
use Engine\Orders\Orders;
use Engine\Orders\ProductEngine;
use Engine\Orders\PaymentEngine;

$pdo = new PDO($dsn, $user, $pass, $options);
$ownerId  = 1;
$senderPsid = 'abc123xyz';
$text     = 'Daj 001';

// uruchom parser
$res = MessageParser::dispatch($pdo, $ownerId, 'messenger', $senderPsid, $text);
print_r($res);
