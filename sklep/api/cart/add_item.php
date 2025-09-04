<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/cart_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$ownerId = (int)($_POST['owner_id'] ?? 0);
$productId = (int)($_POST['product_id'] ?? 0);
$qty = max(1, (int)($_POST['quantity'] ?? 1));

if ($ownerId <= 0 || $productId <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Brak owner_id lub product_id']); exit;
}

$sessionId = getCartSessionId();
$clientToken = getClientToken();

// snapshot produktu
$stmt = $pdo->prepare("SELECT price, weight FROM products WHERE id=? AND owner_id=? LIMIT 1");
$stmt->execute([$productId, $ownerId]);
$prod = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$prod) { echo json_encode(['ok'=>false,'error'=>'Produkt nie istnieje']); exit; }

$pdo->beginTransaction();
try {
    // UPSERT do koszyka
    $ins = $pdo->prepare("
        INSERT INTO cart_items (owner_id, session_id, client_token, product_id, quantity, unit_price, weight_kg)
        VALUES (:o,:s,:t,:p,:q,:price,:w)
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at=NOW()
    ");
    $ins->execute([
        ':o'=>$ownerId, ':s'=>$sessionId, ':t'=>$clientToken,
        ':p'=>$productId, ':q'=>$qty,
        ':price'=>(float)$prod['price'], ':w'=>$prod['weight'] ?? null
    ]);

    $pdo->commit();
    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Błąd dodawania']);
}
