<?php
// admin/products/partials/search_products.php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$q       = trim($_GET['q'] ?? '');
$catId   = trim($_GET['category_id'] ?? '');

$params = ['owner'=>$ownerId];
$where  = ["p.owner_id = :owner"];

if ($q !== '') {
  $where[] = "(p.name LIKE :q OR p.code LIKE :q)";
  $params['q'] = '%'.$q.'%';
}
if ($catId !== '' && ctype_digit($catId)) {
  $where[] = "p.category_id = :cat";
  $params['cat'] = (int)$catId;
}

$sql = "
SELECT
  p.id, p.code, p.name,
  p.price,
  COALESCE(p.stock_cached, p.stock) AS stock,
  p.weight,
  p.vat_rate,
  p.twelve_nc,
  p.category_id
FROM products p
WHERE ".implode(' AND ', $where)."
ORDER BY p.name ASC
LIMIT 200
";

try {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  http_response_code(200);
  echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>true, 'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
