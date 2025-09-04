<?php
declare(strict_types=1);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (headers_sent()) {
  echo json_encode(['success' => false, 'error' => '❌ Headers already sent']);
  exit;
}
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../engine/live/LiveEngine.php';
require_once __DIR__ . '/../../engine/orders/ClientEngine.php';
require_once __DIR__ . '/../../engine/orders/ProductEngine.php';
require_once __DIR__ . '/../../engine/orders/PaymentEngine.php';

define('LIVE_ADD_DEBUG', isset($_GET['debug']) && $_GET['debug'] === '1');



// Walidacja sesji
if (!isset($_SESSION['user']['owner_id'], $_SESSION['user']['id'])) {
  echo json_encode([
  'success' => false,
  'error'   => '❌ Brakuje live_id w POST',
  'debug'   => $_POST, // <-- pokaże czy w ogóle coś przyszło
]);
  exit;
}

$owner_id = (int)$_SESSION['user']['owner_id'];
$operator_id = (int)$_SESSION['user']['id'];
$live_id = (int)($_POST['live_id'] ?? 0);
$product_id = (int)($_POST['product_search'] ?? 0);
$client_tokens = $_POST['client_search'] ?? [];
$qty_list = $_POST['qty'] ?? [];
error_log('▶️ POST: ' . print_r($_POST, true));
if ($live_id <= 0) {
  echo json_encode(['success' => false, 'error' => '❌ Brakuje live_id w POST']);
  exit;
}

$isCustom = !empty($_POST['is_custom']) || !empty($_POST['custom_name']);
$custom_data = [];

if ($isCustom) {
  $custom_data = [
    'name'     => trim($_POST['custom_name'] ?? ''),
    'sku'      => trim($_POST['custom_sku'] ?? ''),
    'price'    => (float)($_POST['custom_price'] ?? 0),
    'vat_rate' => (float)($_POST['custom_vat'] ?? 23),
  ];

  if (!$custom_data['name'] || $custom_data['price'] <= 0) {
    echo json_encode(['success' => false, 'error' => '❌ Brak wymaganych danych dla produktu niestandardowego']);
    exit;
  }
} else {
  if ($product_id <= 0) {
    echo json_encode(['success' => false, 'error' => '❌ Nie wybrano produktu katalogowego']);
    exit;
  }
}

// Inicjalizacja engine
$engine = new \Engine\Live\LiveEngine(
  $pdo,
  new \Engine\Orders\ClientEngine($pdo),
  new \Engine\Orders\ProductEngine($pdo),
  new \Engine\Orders\PaymentEngine($pdo)
);

$added = [];

foreach ($client_tokens as $i => $token) {
  $qty = (int)($qty_list[$i] ?? 0);
  if (!$token || $qty <= 0) continue;

  $payload = [
    'owner_id'         => $owner_id,
    'live_id'          => $live_id,
    'operator_user_id' => $operator_id,
    'client_token'     => trim($token),
    'qty'              => $qty,
  ] + (
    $isCustom
      ? $custom_data + ['source_type' => 'custom']
      : ['product_id' => $product_id, 'source_type' => 'catalog']
  );
$result = $engine->addItem($payload);
if (!$result['ok']) {
    echo json_encode(['success' => false, 'error' => '❌ addItem error', 'details' => $result]);
    exit;
}$result = $engine->addItem($payload);

  if (!$result['ok']) {
    echo json_encode(['success' => false, 'error' => $result['error'] ?? '❌ Nieznany błąd']);
    exit;
  }

  $added[] = $result;
}

// Sukces
echo json_encode([
  'success' => true,
  'added' => $added
]);
exit;
