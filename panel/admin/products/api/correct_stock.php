<?php
require_once __DIR__ . '/../../../bootstrap.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$product_id = $_POST['product_id'] ?? null;
$new_stock = (int) ($_POST['new_stock'] ?? 0);
$note = $_POST['note'] ?? '';

if (!$product_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak ID produktu']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT stock FROM products WHERE id = :id AND owner_id = :owner_id");
    $stmt->execute(['id' => $product_id, 'owner_id' => $owner_id]);
    $current_stock = $stmt->fetchColumn();

    if ($current_stock === false) {
        throw new Exception("Produkt nie istnieje lub nie należy do użytkownika.");
    }

    $difference = $new_stock - $current_stock;

    $stmt = $pdo->prepare("UPDATE products SET stock = :new_stock WHERE id = :id AND owner_id = :owner_id");
    $stmt->execute([
        'new_stock' => $new_stock,
        'id' => $product_id,
        'owner_id' => $owner_id
    ]);

    $stmt = $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, purchase_price, note)
                          VALUES (:product_id, 'korekta', :quantity, 0, :note)");
    $stmt->execute([
        'product_id' => $product_id,
        'quantity' => $difference,
        'note' => $note
    ]);

    $pdo->commit();

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
