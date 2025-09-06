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

if (!$product_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak ID produktu']);
    exit;
}

try {
    // Usunięcie tylko, jeśli produkt należy do właściciela i nie ma powiązanych ruchów lub zamówień (opcjonalnie dodaj sprawdzenie)
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = :id AND owner_id = :owner_id");
    $stmt->execute([
        'id' => $product_id,
        'owner_id' => $owner_id
    ]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Produkt nie istnieje lub nie należy do użytkownika.");
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
