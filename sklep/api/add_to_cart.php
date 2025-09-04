<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');
$product_id = (int)($input['product_id'] ?? 0);
$qty = (int)($input['quantity'] ?? 1);
$debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';

$debug = [
  'token_received' => $token,
  'product_id' => $product_id,
  'quantity' => $qty,
  'step' => 'start'
];

if (!$token || !$product_id || $qty < 1) {
  echo json_encode(['error' => 'Brak danych wejściowych.', 'debug' => $debug_mode ? $debug : null]);
  exit;
}

// 🔍 1. Szukamy klienta po client_token
$stmt = $pdo->prepare("SELECT id, owner_id FROM clients WHERE token = ?");
$stmt->execute([$token]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if ($client) {
  $client_id = $client['id'];
  $debug['found_client'] = $client_id;
} else {
  // 🔍 2. Jeśli nie znaleziono → spróbuj po checkout_token
  $stmt = $pdo->prepare("
        SELECT c.id, c.owner_id 
        FROM order_groups og
        JOIN orders o ON og.order_id = o.id
        JOIN clients c ON o.client_id = c.id
        WHERE og.checkout_token = ?
        LIMIT 1
    ");
  $stmt->execute([$token]);
  $client = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($client) {
    $client_id = $client['id'];
    $debug['found_client_by_checkout'] = $client_id;
  } else {
    echo json_encode(['error' => 'Niepoprawny token klienta.', 'debug' => $debug_mode ? $debug : null]);
    exit;
  }
}

// 🔍 3. Sprawdź produkt
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND active = 1");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$product) {
  echo json_encode(['error' => 'Produkt nie istnieje.', 'debug' => $debug_mode ? $debug : null]);
  exit;
}
$debug['product_price'] = $product['price'];

// 🔍 4. Sprawdź otwartą paczkę
$stmt = $pdo->prepare("
    SELECT og.id AS order_group_id, o.id AS order_id
    FROM orders o
    JOIN order_groups og ON og.order_id = o.id
    WHERE o.client_id = :cid AND o.status = 'otwarta_paczka'
    LIMIT 1
");
$stmt->execute(['cid' => $client_id]);
$open_order = $stmt->fetch(PDO::FETCH_ASSOC);

$debug['open_order_found'] = $open_order;

// 📦 5. Jeśli paczka otwarta → dodaj do order_items
if ($open_order) {
  $stmt = $pdo->prepare("
        SELECT id, quantity FROM order_items 
        WHERE order_group_id = ? AND product_id = ?
    ");
  $stmt->execute([$open_order['order_group_id'], $product_id]);
  $existing = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
    $new_qty = $existing['quantity'] + $qty;
    $stmt = $pdo->prepare("UPDATE order_items SET quantity = ? WHERE id = ?");
    $stmt->execute([$new_qty, $existing['id']]);
    $debug['update_qty'] = $new_qty;
  } else {
    $stmt = $pdo->prepare("
            INSERT INTO order_items (order_group_id, product_id, quantity, price, source) 
            VALUES (?, ?, ?, ?, 'sklep')
        ");
    $stmt->execute([
      $open_order['order_group_id'],
      $product_id,
      $qty,
      $product['price']
    ]);
    $debug['insert_new'] = true;
  }

  echo json_encode(['success' => true, 'message' => 'Dodano do otwartej paczki', 'debug' => $debug_mode ? $debug : null]);
  exit;
}

// 🛒 6. Brak otwartej paczki → standardowy koszyk
$stmt = $pdo->prepare("SELECT id, quantity FROM cart_items WHERE client_id = ? AND product_id = ?");
$stmt->execute([$client_id, $product_id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
  $new_qty = $existing['quantity'] + $qty;
  $stmt = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE id = ?");
  $stmt->execute([$new_qty, $existing['id']]);
  $debug['cart_update_qty'] = $new_qty;
} else {
  $stmt = $pdo->prepare("INSERT INTO cart_items (client_id, product_id, quantity) VALUES (?, ?, ?)");
  $stmt->execute([$client_id, $product_id, $qty]);
  $debug['cart_insert'] = true;
}

echo json_encode(['success' => true, 'message' => 'Dodano do koszyka', 'debug' => $debug_mode ? $debug : null]);
