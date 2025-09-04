<?php
// admin/live/ajax_client_search.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

$owner_id = (int) ($_SESSION['user']['owner_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

if (!$owner_id || !$q || strlen($q) < 2) {
  echo json_encode([]);
  exit;
}

$sql = "SELECT token, name FROM clients
        WHERE owner_id = ?
        AND (name LIKE ? OR token LIKE ?)
        ORDER BY name ASC LIMIT 20";
$like = "%$q%";
$stmt = $pdo->prepare($sql);
$stmt->execute([$owner_id, $like, $like]);

$out = [];
foreach ($stmt as $row) {
  $out[] = [
    'id' => $row['token'],
    'text' => $row['name'] . ' [' . $row['token'] . ']'
  ];
}

echo json_encode($out);
