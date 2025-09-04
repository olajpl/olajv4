<?php

declare(strict_types=1);
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'logged_in' => !empty($_SESSION['client_id']) && !empty($_SESSION['client_token']),
    'client_id' => (int)($_SESSION['client_id'] ?? 0),
    'name'      => (string)($_SESSION['client_name'] ?? ''), // jeśli trzymasz
]);
