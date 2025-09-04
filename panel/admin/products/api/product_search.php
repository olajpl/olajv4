<?php
// api/product_search.php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

header('Content-Type: application/json');

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$q = trim($_GET['q'] ?? '');

if (!$owner_id || strlen($q) < 2) {
  echo json_encode([]);
  exit;
}

$query = "%{$q}%";
$stmt = $pdo->prepare("
  SELECT id, name, code, twelve_nc, price 
  FROM products 
  WHERE owner_id = :owner_id AND (
    name LIKE :query OR code LIKE :query OR twelve_nc LIKE :query
  )
  ORDER BY name ASC 
  LIMIT 10
");
$stmt->execute([
  'owner_id' => $owner_id,
  'query' => $query
]);

$results = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $results[] = [
    'id' => $row['id'],
    'name' => $row['name'],
    'code' => $row['code'],
    'price' => $row['price'],
    'label' => "{$row['name']} ({$row['code']}) – " . number_format($row['price'], 2, ',', ' ') . " zł"
  ];
}

echo json_encode($results);
