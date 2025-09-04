<?php
use PDO;
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../engine/live/LiveEngine.php';
require_once __DIR__ . '/../../engine/orders/ClientEngine.php';
require_once __DIR__ . '/../../engine/orders/ProductEngine.php';
require_once __DIR__ . '/../../engine/orders/PaymentEngine.php';

header('Content-Type: application/json');

$live_temp_id = (int)($_POST['live_temp_id'] ?? 0);
$live_id = (int)($_POST['live_id'] ?? 0);
$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$user_id = (int)($_SESSION['user']['id'] ?? 0);

if ($live_temp_id <= 0 || $live_id <= 0 || $owner_id <= 0) {
  echo json_encode(['success' => false, 'error' => 'Błędne dane wejściowe.']);
  exit;
}

try {
  $engine = new \Engine\Live\LiveEngine(
    $pdo,
    new \Engine\Orders\ClientEngine($pdo),
    new \Engine\Orders\ProductEngine($pdo),
    new \Engine\Orders\PaymentEngine($pdo)
  );

  $ok = $engine->deleteItem([
    'id' => $live_temp_id,
    'live_id' => $live_id,
    'owner_id' => $owner_id,
    'operator_user_id' => $user_id
  ]);

  echo json_encode(['success' => $ok]);
} catch (Throwable $e) {
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
