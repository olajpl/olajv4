<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../engine/Marketing/RaffleEngine.php';

use Engine\Marketing\RaffleEngine;

header('Content-Type: application/json; charset=utf-8');

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$draw_id  = (int)($_POST['draw_id'] ?? 0);
$name     = trim($_POST['name'] ?? '');

/* Fallback ownera z draw_id */
if ($owner_id <= 0 && $draw_id > 0) {
    $st = $pdo->prepare("SELECT owner_id FROM draws WHERE id=:id LIMIT 1");
    $st->execute(['id' => $draw_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) $owner_id = (int)$row['owner_id'];
}

if ($owner_id <= 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Brak kontekstu właściciela']);
    exit;
}
if ($name === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Podaj nazwę klienta']);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO clients (owner_id, name) VALUES (:oid, :name)");
    $stmt->execute(['oid' => $owner_id, 'name' => $name]);
    $id = (int)$pdo->lastInsertId();
    echo json_encode(['success' => true, 'id' => $id, 'name' => $name]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
