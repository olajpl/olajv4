<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../engine/live/LiveEngine.php';
require_once __DIR__ . '/../../engine/orders/ClientEngine.php';
require_once __DIR__ . '/../../engine/orders/ProductEngine.php';
require_once __DIR__ . '/../../engine/orders/PaymentEngine.php';

header('Content-Type: application/json');

$live_id = (int)($_POST['live_id'] ?? 0);
$operator_id = (int)($_SESSION['user']['id'] ?? 0);
$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);

if ($live_id <= 0 || $operator_id <= 0 || $owner_id <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Brak wymaganych danych']);
  exit;
}

try {
  $engine = new \Engine\Live\LiveEngine(
    $pdo,
    new \Engine\Orders\ClientEngine($pdo),
    new \Engine\Orders\ProductEngine($pdo),
    new \Engine\Orders\PaymentEngine($pdo)
  );

 $result = $engine->finalizeBatch([
  'live_id' => $live_id,
  'owner_id' => $owner_id,
  'operator_user_id' => $operator_id
]);


  echo json_encode([
    'success' => true,
    'finalized_count' => $result['finalized'] ?? 0
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
