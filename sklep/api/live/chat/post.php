<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../includes/session.php';
require_once __DIR__ . '/../../../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$live_id = (int)($body['live_id'] ?? 0);
$msg     = trim((string)($body['message'] ?? ''));

if ($live_id < 1 || $msg === '') {
    echo json_encode(['success' => false, 'error' => 'brak danych']);
    exit;
}

if (empty($_SESSION['client_id'])) {
    echo json_encode(['success' => false, 'error' => 'login_required']);
    exit;
}

$client_id = (int)$_SESSION['client_id'];
$owner_id  = (int)($_SESSION['client_owner_id'] ?? 1);

// proste limity
$st = $pdo->prepare("SELECT COUNT(*) FROM live_comments WHERE client_id=:cid AND created_at > (NOW() - INTERVAL 2 SECOND)");
$st->execute([':cid' => $client_id]);
if ((int)$st->fetchColumn() > 0) {
    echo json_encode(['success' => false, 'error' => 'za szybko']);
    exit;
}

$st = $pdo->prepare("SELECT COUNT(*) FROM live_comments WHERE client_id=:cid AND created_at > (NOW() - INTERVAL 1 MINUTE)");
$st->execute([':cid' => $client_id]);
if ((int)$st->fetchColumn() > 20) {
    echo json_encode(['success' => false, 'error' => 'limit minutowy']);
    exit;
}

$msg = mb_substr($msg, 0, 500);

$ins = $pdo->prepare("
  INSERT INTO live_comments (owner_id, live_stream_id, client_id, source, message)
  VALUES (:o, :l, :c, 'shop', :m)
");
$ok = $ins->execute([':o' => $owner_id, ':l' => $live_id, ':c' => $client_id, ':m' => $msg]);

echo json_encode(['success' => $ok, 'id' => $ok ? (int)$pdo->lastInsertId() : null]);
