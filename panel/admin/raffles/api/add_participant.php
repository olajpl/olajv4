<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../engine/Marketing/RaffleEngine.php';

use Engine\Marketing\RaffleEngine;

header('Content-Type: application/json');

$owner_id  = (int)($_SESSION['user']['owner_id'] ?? 0);
$draw_id   = (int)($_POST['draw_id'] ?? 0);
$client_id = (int)($_POST['client_id'] ?? 0);

if ($owner_id <= 0 || $draw_id <= 0 || $client_id <= 0) {
    echo json_encode(['error' => 'Brak wymaganych danych']);
    exit;
}

$stmt = $pdo->prepare("INSERT IGNORE INTO draw_participants (draw_id, client_id, owner_id, joined_at) VALUES (:did, :cid, :oid, NOW())");
$stmt->execute([
    'did' => $draw_id,
    'cid' => $client_id,
    'oid' => $owner_id
]);

echo json_encode(['success' => true]);
