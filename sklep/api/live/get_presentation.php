<?php
// api/live/get_presentation.php
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

$owner_id = (int)($_GET['owner_id'] ?? 0);
$live_id  = (int)($_GET['live_id'] ?? 0);

if ($owner_id <= 0 || $live_id <= 0) {
    echo json_encode(['error' => 'Brak parametrów']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT lp.product_id, lp.created_at, p.name, p.code, p.price
    FROM live_presentations lp
    JOIN products p ON p.id = lp.product_id
    WHERE lp.owner_id = :oid AND lp.live_stream_id = :lid
    ORDER BY lp.id DESC
    LIMIT 1
");
$stmt->execute(['oid' => $owner_id, 'lid' => $live_id]);
$presentation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$presentation) {
    echo json_encode(['product' => null]);
} else {
    echo json_encode(['product' => $presentation]);
}
