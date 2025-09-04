<?php
// checkout/start.php — przerzut koszyka → zamówienie (otwarta paczka lub nowa)
declare(strict_types=1);
ob_start();
session_start();

require_once __DIR__ . '/../includes/checkout_loader.php'; // ← ładuje db + helpers + $checkout

// jeżeli potrzebujesz helperów jawnie (typy/stałe) – są już załadowane przez loader

$closedStatuses = ['gotowe_do_wysyłki', 'w_realizacji', 'wysłane', 'zrealizowane', 'anulowane', 'zarchiwizowane'];

function getCartSessionId(): string
{
    if (!isset($_COOKIE['cart_sid']) || !preg_match('/^[a-z0-9]{32}$/', $_COOKIE['cart_sid'])) {
        $sid = bin2hex(random_bytes(16));
        setcookie('cart_sid', $sid, time() + 60 * 60 * 24 * 30, '/', '', false, true);
        $_COOKIE['cart_sid'] = $sid;
    }
    return $_COOKIE['cart_sid'];
}


// ———————————————————————————————————————————————————————————
// Konfiguracja / wejście
// ———————————————————————————————————————————————————————————
$ownerId     = (int)($_GET['owner_id'] ?? ($_SESSION['owner_id'] ?? 0));
$clientId    = isset($_SESSION['client_id']) ? (int)$_SESSION['client_id'] : null;
$clientToken = (string)($_SESSION['client_token'] ?? '');

if ($ownerId <= 0) {
    http_response_code(400);
    exit('Brak owner_id.');
}

// ———————————————————————————————————————————————————————————
// Pomocnicze
// ———————————————————————————————————————————————————————————
function getOpenOrder(PDO $pdo, int $ownerId, ?int $clientId, string $clientToken): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, order_status, checkout_token
        FROM orders
        WHERE owner_id = :owner
          AND ( (client_id IS NOT NULL AND client_id = :cid) OR (client_token <> '' AND client_token = :ctok) )
          AND order_status = 'otwarta_paczka'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':owner' => $ownerId,
        ':cid'   => $clientId ?? 0,
        ':ctok'  => $clientToken,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getLastOrder(PDO $pdo, int $ownerId, ?int $clientId, string $clientToken): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, order_status, checkout_token
        FROM orders
        WHERE owner_id = :owner
          AND ( (client_id IS NOT NULL AND client_id = :cid) OR (client_token <> '' AND client_token = :ctok) )
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':owner' => $ownerId,
        ':cid'   => $clientId ?? 0,
        ':ctok'  => $clientToken,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}


function createNewOrder(PDO $pdo, int $ownerId, ?int $clientId, string $clientToken): array
{
    $checkoutToken = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare("
        INSERT INTO orders (owner_id, client_id, client_token, order_status, checkout_token, created_at, updated_at)
        VALUES (:owner, :cid, :ctok, 'otwarta_paczka', :tok, NOW(), NOW())
    ");
    $stmt->execute([
        ':owner' => $ownerId,
        ':cid'   => $clientId,
        ':ctok'  => $clientToken,
        ':tok'   => $checkoutToken,
    ]);
    return [
        'id'             => (int)$pdo->lastInsertId(),
        'order_status'   => 'otwarta_paczka',
        'checkout_token' => $checkoutToken,
    ];
}

function getProductSnapshot(PDO $pdo, int $productId, int $ownerId): ?array
{
    $s = $pdo->prepare("SELECT id, price, weight FROM products WHERE id=? AND owner_id=? AND active=1 LIMIT 1");
    $s->execute([$productId, $ownerId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

// ———————————————————————————————————————————————————————————
// 1) Zbierz koszyk (DB jeśli zalogowany; inaczej sesja)
// ———————————————————————————————————————————————————————————
$cartItems = [];

if (!empty($clientId)) {
    // Koszyk w DB po client_id
    $stmt = $pdo->prepare("SELECT product_id, quantity FROM cart_items WHERE client_id = ? AND quantity > 0");
    $stmt->execute([$clientId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Fallback: koszyk w DB po session_id
    $sessionId = getCartSessionId();
    $stmt = $pdo->prepare("SELECT product_id, quantity FROM cart_items WHERE session_id = ? AND quantity > 0");
    $stmt->execute([$sessionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Jeśli w DB pusto → koszyk z $_SESSION
    if (!$rows) {
        $sessionCart = $_SESSION['cart'] ?? [];
        foreach ($sessionCart as $row) {
            $rows[] = [
                'product_id' => (int)($row['product_id'] ?? 0),
                'quantity'   => (int)($row['quantity'] ?? 1),
            ];
        }
    }
}

// Teraz $rows przetwarzamy na $cartItems (snapshoty cen/wag)
foreach ($rows as $row) {
    $pid = (int)($row['product_id'] ?? 0);
    $qty = max(1, (int)($row['quantity'] ?? 1));
    if ($pid <= 0) continue;
    $snap = getProductSnapshot($pdo, $pid, $ownerId);
    if ($snap) {
        $cartItems[] = [
            'product_id' => $pid,
            'quantity'   => $qty,
            'unit_price' => (float)$snap['price'],
        ];
    }
}


if (!$cartItems) {
    header('Location: /cart/index.php?e=empty');
    exit;
}

// 1.5) Jeśli ostatnie zamówienie jest zamknięte → nie próbuj go używać
$last = getLastOrder($pdo, $ownerId, $clientId, $clientToken);
if ($last && in_array((string)$last['order_status'], $closedStatuses, true)) {
    // upewnij się, że nie ma żadnej otwartej paczki; i tak za chwilę utworzymy nowe
    $order = null; // wymuś ścieżkę "createNewOrder"
}

// ———————————————————————————————————————————————————————————
// 2) Znajdź otwartą paczkę lub utwórz nową (nigdy nie dotykaj zamkniętej)
// ———————————————————————————————————————————————————————————
if (!isset($order) || !$order) {
    $order = getOpenOrder($pdo, $ownerId, $clientId, $clientToken);
}
if (!$order) {
    $order = createNewOrder($pdo, $ownerId, $clientId, $clientToken);
}
$orderId = (int)$order['id'];


// Jeśli z jakiegoś powodu paczka została zamknięta → twórz nową (żelazna zasada)
$st = $pdo->prepare("SELECT order_status FROM orders WHERE id=? FOR UPDATE");
$pdo->beginTransaction();
try {
    $st->execute([$orderId]);
    $curr = (string)$st->fetchColumn();
    if ($curr !== 'otwarta_paczka') {
        $pdo->commit(); // domknij, żeby nie trzymać locka
        $order = createNewOrder($pdo, $ownerId, $clientId, $clientToken);
        $orderId = (int)$order['id'];
        $pdo->beginTransaction();
        $st->execute([$orderId]);
        $curr = (string)$st->fetchColumn(); // powinno być 'otwarta_paczka'
    }

    // ———————————————————————————————————————————————————————————
    // 3) Wrzut pozycji do order_items (UPSERT ilości)
    // ———————————————————————————————————————————————————————————
    $ins = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, quantity, unit_price, created_at)
        VALUES (:oid, :pid, :qty, :price, NOW())
        ON DUPLICATE KEY UPDATE 
            quantity = quantity + VALUES(quantity),
            unit_price = VALUES(unit_price), 
            updated_at = NOW()
    ");

    foreach ($cartItems as $it) {
        $ins->execute([
            ':oid'   => $orderId,
            ':pid'   => (int)$it['product_id'],
            ':qty'   => (int)$it['quantity'],
            ':price' => (float)$it['unit_price'],
        ]);
    }

    // ———————————————————————————————————————————————————————————
    // 4) Czyść koszyk
    // ———————————————————————————————————————————————————————————
    if (!empty($clientId)) {
        $del = $pdo->prepare("DELETE FROM cart_items WHERE client_id = ?");
        $del->execute([$clientId]);
    } else {
        // Czyść DB po session_id
        $sessionId = getCartSessionId();
        $del = $pdo->prepare("DELETE FROM cart_items WHERE session_id = ?");
        $del->execute([$sessionId]);
        // Czyść ewentualny koszyk w $_SESSION
        unset($_SESSION['cart']);
    }


    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    exit('Błąd przenoszenia koszyka do zamówienia.');
}

// ———————————————————————————————————————————————————————————
header('Location: /checkout/index.php?token=' . urlencode($order['checkout_token']));
exit;
