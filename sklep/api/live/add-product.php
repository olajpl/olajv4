<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../engine/OrderEngine.php';

$data = json_decode(file_get_contents('php://input'), true);
$live_id = (int)($data['live_id'] ?? 0);

if (!$live_id) {
    echo json_encode(['error' => 'Brak live_id']);
    exit;
}

// Pobierz produkt z aktywnej oferty
$stmt = $pdo->prepare("
    SELECT p.id, p.price
    FROM live_active_offers o
    JOIN products p ON o.product_id = p.id
    WHERE o.live_id = :live_id
    ORDER BY o.start_time DESC
    LIMIT 1
");
$stmt->execute(['live_id' => $live_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo json_encode(['error' => 'Brak aktywnej oferty']);
    exit;
}

// Dodaj do paczki (Centralny Dodawacz)
$orderEngine = new OrderEngine($pdo);
$result = $orderEngine->addItem([
    'product_id' => $product['id'],
    'qty' => 1,
    'price' => $product['price'],
    'origin' => 'live_shop',
    'origin_ref' => $live_id
]);

echo json_encode(['success' => true, 'result' => $result]);
