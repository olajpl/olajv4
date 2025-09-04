<?php
// admin/live/ajax_product_search.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

$owner_id = (int) ($_SESSION['user']['owner_id'] ?? 0);
$query = trim($_GET['q'] ?? '');

if (!$owner_id || !$query || strlen($query) < 2) {
  echo json_encode([]);
  exit;
}

$sql = "SELECT id, name, code FROM products
        WHERE owner_id = ? AND active = 1
          AND (name LIKE ? OR code LIKE ?)
        ORDER BY name ASC LIMIT 20";
$stmt = $pdo->prepare($sql);
$like = "%$query%";
$stmt->execute([$owner_id, $like, $like]);

$results = [];
foreach ($stmt as $row) {
  $results[] = [
    'id' => $row['id'],
    'text' => $row['name'] . ' [' . $row['code'] . ']'
  ];
}

echo json_encode($results);
