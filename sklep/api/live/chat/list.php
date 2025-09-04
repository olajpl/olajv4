<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../includes/session.php';
require_once __DIR__ . '/../../../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

$live_id = (int)($_GET['live_id'] ?? 0);
$after   = (int)($_GET['after_id'] ?? 0);
$limit   = min(100, max(1, (int)($_GET['limit'] ?? 50)));
if ($live_id < 1) {
    echo json_encode(['success' => false, 'error' => 'live_id']);
    exit;
}

$sql = "
  SELECT lc.id, lc.client_id, COALESCE(c.name, 'Klient') AS display_name,
         lc.message, lc.created_at
  FROM live_comments lc
  LEFT JOIN clients c ON c.id = lc.client_id
  WHERE lc.live_stream_id = :live AND lc.moderation = 'clean'
";
$params = [':live' => $live_id];
if ($after > 0) {
    $sql .= " AND lc.id > :after";
    $params[':after'] = $after;
}
$sql .= " ORDER BY lc.id ASC LIMIT {$limit}";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'items' => $rows,
    'last_id' => $rows ? (int)end($rows)['id'] : $after
]);
