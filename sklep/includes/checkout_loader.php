<?php
// shop/checkout/includes/checkout_loader.php
// V4: rozwiązuje tokeny chk-* (orders.checkout_token) i grp-* (order_groups.group_token)

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/** Prosty prefix-check bez mb_* */
function str_starts_with_simple(string $haystack, string $prefix): bool
{
    return substr($haystack, 0, strlen($prefix)) === $prefix;
}

/**
 * 1) token z URL (?token=)
 * 2) token z sesji
 * 3) fallback: client_token → ostatni checkout (orders.checkout_token)
 */
function resolveAnyToken(PDO $pdo): ?string
{
    // 1) GET ?token=
    $t = $_GET['token'] ?? null;
    if (is_string($t) && $t !== '') return $t;

    // 2) SESSION
    $t = $_SESSION['checkout_token'] ?? null;
    if (is_string($t) && $t !== '') return $t;

    // 3) Fallback po client_token → ostatni checkout (chk-*)
    $ct = $_COOKIE['client_token'] ?? '';
    if (!is_string($ct) || $ct === '') return null;

    $q = $pdo->prepare("
        SELECT o.checkout_token
        FROM clients c
        JOIN orders o ON o.client_id = c.id
        WHERE c.token = :ct AND o.checkout_token IS NOT NULL AND o.checkout_token <> ''
        ORDER BY o.id DESC
        LIMIT 1
    ");
    $q->execute(['ct' => $ct]);
    $t = $q->fetchColumn();
    return $t ? (string)$t : null;
}

/** Generator tokenów chk-* (orders.checkout_token) z kontrolą kolizji. */
function makeCheckoutToken(PDO $pdo): string
{
    for ($i = 0; $i < 6; $i++) {
        $raw = bin2hex(random_bytes(8)); // krótszy, czytelny
        $tok = 'chk-' . $raw;

        $chk = $pdo->prepare("SELECT 1 FROM orders WHERE checkout_token = ? LIMIT 1");
        $chk->execute([$tok]);
        if (!$chk->fetchColumn()) return $tok;
    }
    return 'chk-' . bin2hex(random_bytes(12));
}

/** Generator tokenów grp-* (order_groups.group_token) z kontrolą kolizji. */
function makeGroupToken(PDO $pdo): string
{
    for ($i = 0; $i < 6; $i++) {
        $raw = bin2hex(random_bytes(8));
        $tok = 'grp-' . $raw;

        $chk = $pdo->prepare("SELECT 1 FROM order_groups WHERE group_token = ? LIMIT 1");
        $chk->execute([$tok]);
        if (!$chk->fetchColumn()) return $tok;
    }
    return 'grp-' . bin2hex(random_bytes(12));
}

/**
 * Zwraca kontekst checkout:
 *  - jeśli jest token → rozwiązuje po chk-* albo grp-*
 *  - jeśli nie ma tokenu, ale jest client_token → tworzy nowe order+group i przenosi koszyk
 * Zwraca: order_id, owner_id, client_id, order_status, order_group_id, token(chk-*), checkout_completed
 */
function loadCheckoutContext(PDO $pdo, ?string $maybeToken): array
{
    // 1) Token z zewnątrz
    if ($maybeToken) {
        if (str_starts_with_simple($maybeToken, 'chk-')) {
            // Rozwiąż po orders.checkout_token
            $st = $pdo->prepare("
                SELECT 
                    o.id              AS order_id,
                    o.owner_id,
                    o.client_id,
                    o.order_status,
                    o.checkout_token  AS token,
                    og.id             AS order_group_id,
                    og.checkout_completed
                FROM orders o
                LEFT JOIN order_groups og ON og.order_id = o.id
                WHERE o.checkout_token = :t
                ORDER BY og.id DESC
                LIMIT 1
            ");
            $st->execute(['t' => $maybeToken]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        } elseif (str_starts_with_simple($maybeToken, 'grp-')) {
            // Rozwiąż po order_groups.group_token
            $st = $pdo->prepare("
                SELECT
                    o.id             AS order_id,
                    o.owner_id,
                    o.client_id,
                    o.order_status,
                    o.checkout_token AS token,
                    og.id            AS order_group_id,
                    og.checkout_completed
                FROM order_groups og
                JOIN orders o ON o.id = og.order_id
                WHERE og.group_token = :t
                LIMIT 1
            ");
            $st->execute(['t' => $maybeToken]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        } else {
            // Nieznany format? Spróbuj jako chk-* (wsteczna kompatybilność)
            $st = $pdo->prepare("
                SELECT 
                    o.id              AS order_id,
                    o.owner_id,
                    o.client_id,
                    o.order_status,
                    o.checkout_token  AS token,
                    og.id             AS order_group_id,
                    og.checkout_completed
                FROM orders o
                LEFT JOIN order_groups og ON og.order_id = o.id
                WHERE o.checkout_token = :t
                ORDER BY og.id DESC
                LIMIT 1
            ");
            $st->execute(['t' => $maybeToken]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }
    }

    // 2) Brak tokenu → spróbuj po client_token (cookie)
    $clientToken = $_COOKIE['client_token'] ?? '';
    if (!is_string($clientToken) || $clientToken === '') {
        return [];
    }

    // klient
    $cst = $pdo->prepare("SELECT id, owner_id FROM clients WHERE token = :t LIMIT 1");
    $cst->execute(['t' => $clientToken]);
    $client = $cst->fetch(PDO::FETCH_ASSOC);
    if (!$client) return [];

    $client_id = (int)$client['id'];
    $owner_id  = (int)$client['owner_id'];

    // ostatnie zamówienie w stanie 'nowe' (lub po prostu ostatnie z checkout_token)
    $ogs = $pdo->prepare("
        SELECT 
            o.id AS order_id,
            o.order_status,
            o.checkout_token AS token,
            og.id AS order_group_id,
            og.checkout_completed
        FROM orders o
        LEFT JOIN order_groups og ON og.order_id = o.id
        WHERE o.client_id = :cid AND o.owner_id = :oid
          AND o.checkout_token IS NOT NULL AND o.checkout_token <> ''
        ORDER BY o.id DESC
        LIMIT 1
    ");
    $ogs->execute(['cid' => $client_id, 'oid' => $owner_id]);
    $og = $ogs->fetch(PDO::FETCH_ASSOC);

    if ($og && !empty($og['order_group_id'])) {
        return [
            'order_id'           => (int)$og['order_id'],
            'owner_id'           => $owner_id,
            'client_id'          => $client_id,
            'order_status'       => (string)$og['order_status'],
            'order_group_id'     => (int)$og['order_group_id'],
            'token'              => (string)$og['token'],
            'checkout_completed' => (int)$og['checkout_completed'],
        ];
    }

    // 3) Tworzymy nowe order + group + przenosimy koszyk
    $pdo->beginTransaction();
    try {
        // 3.1 zamówienie
        $checkout_token = makeCheckoutToken($pdo);
        $insO = $pdo->prepare("
            INSERT INTO orders (client_id, owner_id, order_status, checkout_token, created_at)
            VALUES (:cid, :oid, 'nowe', :tok, NOW())
        ");
        $insO->execute(['cid' => $client_id, 'oid' => $owner_id, 'tok' => $checkout_token]);
        $order_id = (int)$pdo->lastInsertId();

        // 3.2 grupa (paczka)
        $group_token = makeGroupToken($pdo);
        $insG = $pdo->prepare("
            INSERT INTO order_groups (order_id, group_token, created_at)
            VALUES (:oid, :gtok, NOW())
        ");
        $insG->execute(['oid' => $order_id, 'gtok' => $group_token]);
        $order_group_id = (int)$pdo->lastInsertId();

        // 3.3 przeniesienie koszyka → order_items
        // Uwaga: dostosuj kolumny do swojego schematu (qty/quantity, unit_price, vat_rate itd.)
        $cart = $pdo->prepare("
            SELECT ci.product_id, ci.quantity, COALESCE(ci.unit_price, p.price, 0) AS unit_price
            FROM cart_items ci
            LEFT JOIN products p ON p.id = ci.product_id
            WHERE ci.owner_id = :oid AND ci.client_token = :ct AND ci.quantity > 0
        ");
        $cart->execute(['oid' => $owner_id, 'ct' => $clientToken]);
        $items = $cart->fetchAll(PDO::FETCH_ASSOC);

        if ($items) {
            // Jeśli masz UNIQUE(order_group_id, product_id), ON DUPLICATE zadziała
            $insI = $pdo->prepare("
                INSERT INTO order_items (order_group_id, product_id, quantity, unit_price, source, created_at)
                VALUES (:og, :pid, :q, :u, 'shop', NOW())
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
            ");
            foreach ($items as $ci) {
                if ((int)$ci['quantity'] <= 0) continue;
                $insI->execute([
                    'og'  => $order_group_id,
                    'pid' => (int)$ci['product_id'],
                    'q'   => (int)$ci['quantity'],
                    'u'   => (float)$ci['unit_price'],
                ]);
            }

            // wyczyść koszyk
            $del = $pdo->prepare("
                DELETE FROM cart_items
                WHERE owner_id = :oid AND client_token = :ct
            ");
            $del->execute(['oid' => $owner_id, 'ct' => $clientToken]);
        }

        $pdo->commit();

        return [
            'order_id'           => $order_id,
            'owner_id'           => $owner_id,
            'client_id'          => $client_id,
            'order_status'       => 'nowe',
            'order_group_id'     => $order_group_id,
            'token'              => $checkout_token,   // zawsze zwracamy chk-*
            'checkout_completed' => 0,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // TODO: logg('error', 'checkout.loader', 'exception', ['e' => (string)$e]);
        return [];
    }
}

// === GŁÓWNE WEJŚCIE ===
$checkout_token = resolveAnyToken($pdo);
$checkout       = loadCheckoutContext($pdo, $checkout_token);

/* 404 tylko gdy naprawdę nie mamy kontekstu */
if (empty($checkout) || empty($checkout['token'])) {
    http_response_code(404);
    if (!defined('CHECKOUT_404_DONE')) define('CHECKOUT_404_DONE', true);
    require __DIR__ . '/../partials/checkout_404.php';
    exit;
}

// Zamknięty checkout → przerzucamy na podsumowanie (poza thank_you.php)
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (!empty($checkout['checkout_completed']) && $currentScript !== 'thank_you.php') {
    $url = '/checkout/thank_you.php?token=' . urlencode((string)$checkout['token']);
    header('Location: ' . $url);
    // na wypadek wysłanych nagłówków:
    echo '<meta http-equiv="refresh" content="0;url=', htmlspecialchars($url, ENT_QUOTES), '">';
    echo '<script>location.replace(', json_encode($url), ')</script>';
    exit;
}

// Utrwalenie kontekstu w sesji
$_SESSION['checkout_token'] = (string)$checkout['token'];          // chk-*
$_SESSION['order_group_id'] = (int)$checkout['order_group_id'];
$_SESSION['order_id']       = (int)$checkout['order_id'];
if (isset($checkout['owner_id']))  $_SESSION['owner_id']  = (int)$checkout['owner_id'];
if (isset($checkout['client_id'])) $_SESSION['client_id'] = (int)$checkout['client_id'];

// Lokalna zmienna do dalszych include'ów
$checkout_token = (string)$checkout['token'];
