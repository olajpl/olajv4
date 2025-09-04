<?php
/**
 * Scalanie koszyka gościa (po cart_sid) do otwartej paczki klienta (PGZ)
 */
function mergeGuestCartToPGZ(PDO $pdo, int $ownerId, int $clientId, string $clientToken): void {
    $cartSid = $_COOKIE['cart_sid'] ?? null;
    if (!$cartSid) {
        return; // brak koszyka gościa
    }

    // 🔍 Pobierz pozycje z koszyka gościa
    $stmt = $pdo->prepare("
        SELECT ci.product_id, ci.quantity, p.price, p.weight AS weight_kg
        FROM cart_items ci
        JOIN products p ON p.id = ci.product_id
        WHERE ci.owner_id = :oid AND ci.session_id = :sid
    ");
    $stmt->execute([
        'oid' => $ownerId,
        'sid' => $cartSid
    ]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        return; // pusty koszyk
    }

    // 🔍 Szukamy otwartej paczki klienta
    $stmt = $pdo->prepare("
        SELECT o.id AS order_id, og.id AS order_group_id, og.checkout_token
        FROM orders o
        JOIN order_groups og ON og.order_id = o.id
        WHERE o.client_id = :cid
        AND o.owner_id = :oid
        AND o.order_status LIKE 'otwarta_paczka%'
        ORDER BY og.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([
        'cid' => $clientId,
        'oid' => $ownerId
    ]);
    $pgz = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pgz) {
        // Brak paczki → tworzymy zamówienie
        $stmt = $pdo->prepare("
            INSERT INTO orders (owner_id, client_id, order_status, created_at)
            VALUES (:oid, :cid, 'otwarta_paczka:add_products', NOW())
        ");
        $stmt->execute([
            'oid' => $ownerId,
            'cid' => $clientId
        ]);
        $orderId = (int)$pdo->lastInsertId();

        // Tworzymy grupę
        $checkoutToken = generateCheckoutToken();
        $stmt = $pdo->prepare("
            INSERT INTO order_groups (order_id, checkout_token, created_at)
            VALUES (:oid, :token, NOW())
        ");
        $stmt->execute([
            'oid'   => $orderId,
            'token' => $checkoutToken
        ]);
        $orderGroupId = (int)$pdo->lastInsertId();

        // Tworzymy draft płatności (Olaj.pl payments auto-create)
        $stmt = $pdo->prepare("
            INSERT INTO payments (order_id, order_group_id, checkout_token, amount, currency, status, created_at)
            VALUES (:order_id, :ogid, :token, 0, 'PLN', 'oczekujące', NOW())
        ");
        $stmt->execute([
            'order_id' => $orderId,
            'ogid'     => $orderGroupId,
            'token'    => $checkoutToken
        ]);
    } else {
        $orderId       = (int)$pgz['order_id'];
        $orderGroupId  = (int)$pgz['order_group_id'];
        $checkoutToken = $pgz['checkout_token'];
    }

    // 🔄 Dodaj pozycje z koszyka gościa do paczki
    foreach ($items as $it) {
        $stmt = $pdo->prepare("
            SELECT id, quantity FROM order_items
            WHERE order_group_id = :ogid AND product_id = :pid
        ");
        $stmt->execute([
            'ogid' => $orderGroupId,
            'pid'  => $it['product_id']
        ]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $newQty = $existing['quantity'] + $it['quantity'];
            $stmt = $pdo->prepare("
                UPDATE order_items
                SET quantity = :qty
                WHERE id = :id
            ");
            $stmt->execute([
                'qty' => $newQty,
                'id'  => $existing['id']
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO order_items (order_group_id, product_id, quantity, price, source)
                VALUES (:ogid, :pid, :qty, :price, 'sklep')
            ");
            $stmt->execute([
                'ogid'  => $orderGroupId,
                'pid'   => $it['product_id'],
                'qty'   => $it['quantity'],
                'price' => $it['price']
            ]);
        }
    }

    // 💰 Zaktualizuj kwotę płatności
    $stmt = $pdo->prepare("
        UPDATE payments
SET amount = (
  SELECT COALESCE(SUM(quantity * unit_price),0)
  FROM order_items
  WHERE order_group_id = :ogid
),
updated_at = NOW()
WHERE order_group_id = :ogid AND status = 'oczekujące';

    $stmt->execute(['ogid' => $orderGroupId]);

    // 🧹 Usuń koszyk gościa
    $stmt = $pdo->prepare("DELETE FROM cart_items WHERE session_id = :sid AND owner_id = :oid");
    $stmt->execute([
        'sid' => $cartSid,
        'oid' => $ownerId
    ]);
}
