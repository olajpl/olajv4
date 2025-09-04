<?php
// cart/submit.php — konwersja koszyka → orders + order_groups + order_items + payments (draft)
// V4-ready + CartEngine merge/clear
declare(strict_types=1);



require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../engine/Cart/CartEngine.php';

use Engine\Cart\CartEngine;


/* ========== 0) CSRF (pojedyncza, spójna) ========== */

function bootstrapCsrf(): string
{
    $sessionToken = null;
    foreach (['csrf', 'csrf_cart', 'csrf_checkout'] as $key) {
        if (!empty($_SESSION[$key])) {
            $sessionToken = (string)$_SESSION[$key];
            break;
        }
    }
    if ($sessionToken === null) {
        $sessionToken = bin2hex(random_bytes(16));
        $_SESSION['csrf_cart'] = $sessionToken;
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
        setcookie('csrf_cart', $sessionToken, [
            'expires'  => time() + 60 * 60 * 24 * 7,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
    return $sessionToken;
}
$sessionToken = bootstrapCsrf();
$postedCsrf = ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)
    ?? ($_SERVER['HTTP_X_CSRF'] ?? null)
    ?? ($_POST['csrf'] ?? null)
    ?? ($_POST['_csrf'] ?? null)
    ?? ($_POST['csrf_token'] ?? null)
    ?? ($_COOKIE['csrf_cart'] ?? null);

if (!$postedCsrf || !hash_equals($sessionToken, (string)$postedCsrf)) {
    logg('warning', 'cart.submit', 'csrf.invalid', [
        'has_header' => isset($_SERVER['HTTP_X_CSRF_TOKEN']) || isset($_SERVER['HTTP_X_CSRF']),
        'has_post'   => isset($_POST['csrf']) || isset($_POST['_csrf']) || isset($_POST['csrf_token']),
        'has_cookie' => isset($_COOKIE['csrf_cart']),
    ]);
    http_response_code(403);
    exit('❌ CSRF invalid');
}

/* ========== 1) Auth ========== */
$client_id    = (int)($_SESSION['client_id'] ?? 0);
$client_token = (string)($_SESSION['client_token'] ?? '');
if ($client_id <= 0 || $client_token === '') {
    http_response_code(401);
    exit('❌ Brak zalogowanego klienta.');
}

/* ========== 2) Owner ==========
   Uwaga: owner z klienta (owner-scope bezpieczeństwa) */
$stmt = $pdo->prepare("SELECT id, owner_id FROM clients WHERE id = ? LIMIT 1");
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
if (!$client) {
    http_response_code(404);
    exit('❌ Nie znaleziono klienta.');
}
$owner_id = (int)$client['owner_id'];

/* ========== 3) cart_sid (cookie) ========== */
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
if (empty($_COOKIE['cart_sid']) || !preg_match('/^[a-f0-9]{32}$/', (string)$_COOKIE['cart_sid'])) {
    $sid = bin2hex(random_bytes(16));
    setcookie('cart_sid', $sid, ['expires' => time() + 60 * 60 * 24 * 30, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE['cart_sid'] = $sid;
}
$cart_sid = (string)($_COOKIE['cart_sid'] ?? '');

/* ========== 4) Tokeny ========== */
if (!function_exists('generateCheckoutToken')) {
    function generateCheckoutToken(): string
    {
        $raw = bin2hex(random_bytes(16));
        return vsprintf('%s-%s-%s-%s-%s-%s-%s-%s', str_split($raw, 4));
    }
}

/* ========== 5) GLOBAL LOCK (klient) ========== */
try {
    $lockName = sprintf('checkout:client:%d', $client_id);
    $lockStmt = $pdo->prepare("SELECT GET_LOCK(:name, 5)");
    $lockStmt->execute(['name' => $lockName]);
    if ((int)$lockStmt->fetchColumn() !== 1) {
        http_response_code(429);
        exit('❌ System zajęty, spróbuj ponownie za chwilę.');
    }

    /* ========== 6) MERGE: guest cart → client cart przez CartEngine ========== */
    // Spięcie na silniku (nie ręcznie)
    CartEngine::mergeGuestToClient($pdo, $owner_id, $cart_sid, $client_token);

    // Pobierz już zmergowane pozycje klienta (token klienta)
    $clientCart = CartEngine::getCartItems($pdo, $owner_id, $client_token);

    if (empty($clientCart)) {
        // brak pozycji = brak zamówienia
        $pdo->prepare("SELECT RELEASE_LOCK(:name)")->execute(['name' => $lockName]);
        http_response_code(400);
        exit('🧺 Twój koszyk jest pusty.');
    }

    /* ========== 7) Transakcja: budujemy order + group + items + payment ========== */
    $pdo->beginTransaction();

    // 7.1 znajdź zamówienia klienta (FOR UPDATE)
    $ordersStmt = $pdo->prepare("
        SELECT id, order_status, checkout_token
        FROM orders
        WHERE owner_id = :oid AND client_id = :cid
        ORDER BY id DESC
        FOR UPDATE
    ");
    $ordersStmt->execute(['oid' => $owner_id, 'cid' => $client_id]);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    $order_id = 0;
    $og_id = 0;
    $checkout_token = '';
    $group_token = '';
    $found = false;

    // helpery grup
    $findLastIncompleteGroup = $pdo->prepare("
        SELECT id, checkout_token, group_token
        FROM order_groups
        WHERE order_id = :oid AND checkout_completed = 0
        ORDER BY id DESC
        LIMIT 1
    ");
    $nextGroupNoStmt = $pdo->prepare("SELECT COALESCE(MAX(group_number),0)+1 FROM order_groups WHERE order_id = :oid");
    $createGroup = $pdo->prepare("
        INSERT INTO order_groups (order_id, group_number, group_token, checkout_token, checkout_completed)
        VALUES (:oid, :gno, :gtok, :ctok, 0)
    ");

    // 7.A jeśli istnieje 'nowe' → użyj ostatniej niezamkniętej grupy lub stwórz nową
    foreach ($orders as $o) {
        if ((string)$o['order_status'] === 'nowe') {
            $order_id = (int)$o['id'];
            $checkout_token = (string)$o['checkout_token']; // może być puste w starych rekordach

            if ($checkout_token === '' || $checkout_token === null) {
                $checkout_token = generateCheckoutToken();
                $pdo->prepare("UPDATE orders SET checkout_token = :ctok WHERE id = :id")
                    ->execute(['ctok' => $checkout_token, 'id' => $order_id]);
            }

            $findLastIncompleteGroup->execute(['oid' => $order_id]);
            $gr = $findLastIncompleteGroup->fetch(PDO::FETCH_ASSOC);
            if ($gr) {
                $og_id = (int)$gr['id'];
                $group_token = (string)$gr['group_token'];
            } else {
                $nextGroupNoStmt->execute(['oid' => $order_id]);
                $gno = (int)$nextGroupNoStmt->fetchColumn();
                $group_token = generateCheckoutToken();
                $createGroup->execute([
                    'oid'  => $order_id,
                    'gno'  => $gno,
                    'gtok' => $group_token,
                    'ctok' => $checkout_token,
                ]);
                $og_id = (int)$pdo->lastInsertId();
            }
            $found = true;
            break;
        }
    }

    // 7.B jeśli są 'otwarta_paczka:*' → zamknij do 'nowe' i zrób nową grupę
    if (!$found) {
        foreach ($orders as $o) {
            if (preg_match('/^otwarta_paczka/i', (string)$o['order_status'])) {
                $order_id = (int)$o['id'];
                $checkout_token = (string)$o['checkout_token'];
                if ($checkout_token === '' || $checkout_token === null) {
                    $checkout_token = generateCheckoutToken();
                }
                $pdo->prepare("UPDATE orders SET order_status = 'nowe', checkout_token = :ctok WHERE id = :id")
                    ->execute(['ctok' => $checkout_token, 'id' => $order_id]);

                $nextGroupNoStmt->execute(['oid' => $order_id]);
                $gno = (int)$nextGroupNoStmt->fetchColumn();
                $group_token = generateCheckoutToken();
                $createGroup->execute([
                    'oid'  => $order_id,
                    'gno'  => $gno,
                    'gtok' => $group_token,
                    'ctok' => $checkout_token,
                ]);
                $og_id = (int)$pdo->lastInsertId();
                $found = true;
                break;
            }
        }
    }

    // 7.C jeśli nic nie ma → nowe zamówienie + grupa #1
    if (!$found) {
        $checkout_token = generateCheckoutToken();
        $pdo->prepare("
            INSERT INTO orders (client_id, owner_id, order_status, checkout_token)
            VALUES (:cid, :oid, 'nowe', :ctok)
        ")->execute(['cid' => $client_id, 'oid' => $owner_id, 'ctok' => $checkout_token]);
        $order_id = (int)$pdo->lastInsertId();

        $group_token = generateCheckoutToken();
        $createGroup->execute([
            'oid'  => $order_id,
            'gno'  => 1,
            'gtok' => $group_token,
            'ctok' => $checkout_token,
        ]);
        $og_id = (int)$pdo->lastInsertId();
    }

    /* ---- order_items: używamy schematu z update.php (quantity, price) ---- */
    // wykryj kolumny (quantity/price vs qty/unit_price) – elastycznie
    /* ---- order_items: używamy schematu z update.php (quantity, price) ---- */
    $colInfo = $pdo->query("
    SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'
")->fetchAll(PDO::FETCH_COLUMN);

    $hasQuantity = in_array('quantity', $colInfo, true);
    $hasPrice    = in_array('price', $colInfo, true);
    $hasQty      = in_array('qty', $colInfo, true);
    $hasUnit     = in_array('unit_price', $colInfo, true);
    $hasVat      = in_array('vat_rate', $colInfo, true);
    $hasSource   = in_array('source', $colInfo, true); // legacy
    $hasSrcType  = in_array('source_type', $colInfo, true); // nowe

    $sql = '';
    if ($hasQuantity && $hasPrice) {
        $sql = "INSERT INTO order_items (order_group_id, product_id, quantity, price";
        if ($hasVat)      $sql .= ", vat_rate";
        if ($hasSource)   $sql .= ", source";
        if ($hasSrcType)  $sql .= ", source_type";
        $sql .= ") VALUES (:gid, :pid, :q, :p";
        if ($hasVat)      $sql .= ", :vat";
        if ($hasSource)   $sql .= ", :src";
        if ($hasSrcType)  $sql .= ", :stype";
        $sql .= ") ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)";
    } else {
        $sql = "INSERT INTO order_items (order_group_id, product_id, qty, unit_price";
        if ($hasVat)      $sql .= ", vat_rate";
        if ($hasSource)   $sql .= ", source";
        if ($hasSrcType)  $sql .= ", source_type";
        $sql .= ") VALUES (:gid, :pid, :q, :p";
        if ($hasVat)      $sql .= ", :vat";
        if ($hasSource)   $sql .= ", :src";
        if ($hasSrcType)  $sql .= ", :stype";
        $sql .= ") ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)";
    }

    $ins = $pdo->prepare($sql);

    foreach ($clientCart as $row) {
        $pid  = (int)$row['product_id'];
        $qty  = (float)($row['qty'] ?? $row['quantity'] ?? 0);
        $price = (float)($row['unit_price'] ?? $row['price'] ?? 0);
        $vat   = $hasVat ? (float)($row['vat_rate'] ?? 23.00) : null;

        if ($pid <= 0 || $qty <= 0) {
            continue;
        }
        if ($price <= 0) {
            $ps = $pdo->prepare("SELECT COALESCE(unit_price, 0) FROM products WHERE id = :pid AND owner_id = :oid");
            $ps->execute(['pid' => $pid, 'oid' => $owner_id]);
            $price = (float)$ps->fetchColumn();
        }

        $params = [
            ':gid'   => $og_id,
            ':pid'   => $pid,
            ':q'     => $qty,
            ':p'     => $price,
        ];
        if ($hasVat)     $params[':vat'] = $vat;
        if ($hasSource)  $params[':src'] = 'sklep';
        if ($hasSrcType) $params[':stype'] = 'shop';

        $ins->execute($params);
    }


    // wyczyść koszyk (obie ścieżki, na wszelki wypadek)
    CartEngine::clearCart($pdo, $owner_id, $cart_sid);
    CartEngine::clearCart($pdo, $owner_id, $client_token);

    // suma paczki (sum(quantity*price) || sum(qty*unit_price))
    if ($hasQuantity && $hasPrice) {
        $sumQ = $pdo->prepare("SELECT COALESCE(SUM(quantity * price), 0) FROM order_items WHERE order_group_id = :gid");
    } else {
        $sumQ = $pdo->prepare("SELECT COALESCE(SUM(qty * unit_price), 0) FROM order_items WHERE order_group_id = :gid");
    }
    $sumQ->execute(['gid' => $og_id]);
    $amount = (float)$sumQ->fetchColumn();

    // payments: lekki upsert draft (bez dotykania statusu na UPDATE)
    // wykrycie czy status jest ENUM i jaki bezpieczny stan przyjąć przy INSERT
    $payCol = $pdo->prepare("
        SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'status' LIMIT 1
    ");
    $payCol->execute();
    $ctype = (string)($payCol->fetchColumn() ?: '');
    $safePaymentStatus = null;
    if (stripos($ctype, 'enum(') === 0) {
        preg_match_all("/'((?:\\\\'|[^'])*)'/", $ctype, $m2);
        $allowed = array_map(fn($s) => str_replace("\\'", "'", $s), $m2[1] ?? []);
        foreach (['pending', 'oczekujące', 'oczekujace', 'draft', 'started'] as $pref) {
            if (in_array($pref, $allowed, true)) {
                $safePaymentStatus = $pref;
                break;
            }
        }
        if ($safePaymentStatus === null && $allowed) {
            $safePaymentStatus = $allowed[0];
        }
    }

    // payment po checkout_token (grupowy)
    $p = $pdo->prepare("SELECT id FROM payments WHERE checkout_token = :t LIMIT 1");
    $p->execute(['t' => $checkout_token]);
    $payment_id = (int)($p->fetchColumn() ?: 0);

    if ($payment_id) {
        $pdo->prepare("UPDATE payments SET amount = :a, currency = 'PLN', updated_at = NOW() WHERE id = :id")
            ->execute(['a' => $amount, 'id' => $payment_id]);
    } else {
        if ($safePaymentStatus !== null) {
            $pdo->prepare("
                INSERT INTO payments (order_id, order_group_id, amount, currency, status, checkout_token, created_at, updated_at)
                VALUES (:oid, :gid, :a, 'PLN', :st, :ctok, NOW(), NOW())
            ")->execute(['oid' => $order_id, 'gid' => $og_id, 'a' => $amount, 'st' => $safePaymentStatus, 'ctok' => $checkout_token]);
        } else {
            $pdo->prepare("
                INSERT INTO payments (order_id, order_group_id, amount, currency, checkout_token, created_at, updated_at)
                VALUES (:oid, :gid, :a, 'PLN', :ctok, NOW(), NOW())
            ")->execute(['oid' => $order_id, 'gid' => $og_id, 'a' => $amount, 'ctok' => $checkout_token]);
        }
    }

    $pdo->commit();
    $pdo->prepare("SELECT RELEASE_LOCK(:name)")->execute(['name' => $lockName]);

    // Kontekst checkout do sesji + redirect (token = checkout_token)
    $_SESSION['checkout'] = [
        'client_id'      => $client_id,
        'owner_id'       => $owner_id,
        'token'          => $client_token,
        'checkout_token' => $checkout_token,
        'group_token'    => $group_token,
        'order_id'       => $order_id,
        'order_group_id' => $og_id,
    ];

    header("Location: /checkout/index.php?token=" . urlencode($checkout_token));
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!empty($lockName ?? '')) {
        try {
            $pdo->prepare("SELECT RELEASE_LOCK(:name)")->execute(['name' => $lockName]);
        } catch (Throwable $ign) {
        }
    }
    http_response_code(500);
    exit("❌ submit error: " . htmlspecialchars($e->getMessage()));
}
