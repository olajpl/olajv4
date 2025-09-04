<?php
function handle_daj(PDO $pdo, int $client_id, int $owner_id, string $message): array
{
    if (!preg_match('/daj (\d+)([+x](\d+))?/i', $message, $matches)) {
        return ['error' => 'Nie rozpoznano komendy daj'];
    }

    $code = $matches[1];
    $quantity = isset($matches[3]) ? (int)$matches[3] : 1;

    $stmt = $pdo->prepare("SELECT id, name, stock, price FROM products WHERE code = :code AND owner_id = :owner_id LIMIT 1");
    $stmt->execute([':code' => $code, ':owner_id' => $owner_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        sendClientMessage($client_id, "❌ Nie znaleziono produktu o kodzie: $code");
        return ['error' => 'Nie znaleziono produktu'];
    }

    if ($product['stock'] < $quantity) {
        sendClientMessage($client_id, "⚠️ Brak wystarczającej ilości produktu *{$product['name']}*. Dostępne: {$product['stock']}");
        return ['error' => 'Brak stanu'];
    }

    // Znajdź lub utwórz otwarte zamówienie klienta
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE client_id = :client_id AND owner_id = :owner_id AND status = 'nowe' LIMIT 1");
    $stmt->execute([':client_id' => $client_id, ':owner_id' => $owner_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->prepare("INSERT INTO orders (owner_id, client_id, status) VALUES (:owner_id, :client_id, 'nowe')")
            ->execute([':owner_id' => $owner_id, ':client_id' => $client_id]);
        $order_id = $pdo->lastInsertId();
    } else {
        $order_id = $order['id'];
    }

    $pdo->prepare("INSERT INTO order_groups (order_id) VALUES (:order_id)")
        ->execute([':order_id' => $order_id]);
    $group_id = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO order_items (order_group_id, product_id, quantity, price)
        VALUES (:group_id, :product_id, :quantity, :price)")
        ->execute([
            ':group_id' => $group_id,
            ':product_id' => $product['id'],
            ':quantity' => $quantity,
            ':price' => $product['price']
        ]);

    $pdo->prepare("UPDATE products SET stock = stock - :quantity WHERE id = :product_id")
        ->execute([':quantity' => $quantity, ':product_id' => $product['id']]);

    $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, purchase_price, order_id)
        VALUES (:product_id, 'sprzedaż', :quantity, :price, :order_id)")
        ->execute([
            ':product_id' => $product['id'],
            ':quantity' => $quantity,
            ':price' => $product['price'],
            ':order_id' => $order_id
        ]);

    sendClientMessage($client_id, "✅ Dodano {$quantity} szt. produktu *{$product['name']}* do Twojej paczki.");
    return ['success' => true, 'product_id' => $product['id'], 'quantity' => $quantity];
}
