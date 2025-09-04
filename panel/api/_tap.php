<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$h = function_exists('getallheaders') ? getallheaders() : [];
$b = file_get_contents('php://input') ?: '';

@file_put_contents(
    '/tmp/_tap.log',
    sprintf(
        "[%s] %s %s\nHEADERS:%s\nBODY:%s\n----\n",
        date('c'),
        $_SERVER['REMOTE_ADDR'] ?? '-',
        $_SERVER['REQUEST_METHOD'] ?? '-',
        json_encode($h, JSON_UNESCAPED_SLASHES),
        $b
    ),
    FILE_APPEND
);

echo json_encode(['ok' => true, 'ts' => date('c'), 'len' => strlen($b)]);
