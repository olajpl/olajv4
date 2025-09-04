<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$product_id = $_POST['product_id'] ?? null;
$quantity = (int) ($_POST['quantity'] ?? 0);
$purchase_price = (float) ($_POST['purchase_price'] ?? 0);
$note = $_POST['note'] ?? '';

if (!$product_id || $quantity === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak danych wejściowych']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Sprawdź, czy produkt należy do właściciela
    $stmt = $pdo->prepare("SELECT id FROM products WHERE id = :id AND owner_id = :owner_id");
    $stmt->execute(['id' => $product_id, 'owner_id' => $owner_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Nie znaleziono produktu');
    }

    $stmt = $pdo->prepare("UPDATE products SET stock = stock + :quantity WHERE id = :id");
    $stmt->execute(['quantity' => $quantity, 'id' => $product_id]);

    $stmt = $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, purchase_price, note) VALUES (:product_id, 'przyjęcie', :quantity, :purchase_price, :note)");
    $stmt->execute([
        'product_id' => $product_id,
        'quantity' => $quantity,
        'purchase_price' => $purchase_price,
        'note' => $note
    ]);

    $pdo->commit();

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
