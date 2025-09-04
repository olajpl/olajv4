<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../engine/Orders/OrderEngine.php';
require_once __DIR__ . '/../../engine/Enum/OrderStatus.php';

use Engine\Orders\OrderEngine;

$engine = new OrderEngine($pdo);
$res = $engine->findOrCreateOpenGroupForLive(1, 12345, 99);

var_dump($res);
