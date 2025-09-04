<?php
// cart/update.php — zmiana ilości w koszyku (inc/dec/set/remove)
// V4-ready:
// 1) Zalogowany -> order_items (quantity, price) + draft payments
// 2) Gość -> cart_items (cart_sid, qty, unit_price) — przez CartEngine
// 3) Mini-koszyk + free shipping w odpowiedzi

declare(strict_types=1);
session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../engine/Cart/CartEngine.php';

use Engine\Cart\CartEngine;
use PDO;
use Throwable;

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

/* === 0. Awaryjne JSON-y === */
set_error_handler(function ($no, $str, $file, $line) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Błąd serwera', 'debug' => "$str @ $file:$line"]);
    exit;
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Błąd krytyczny', 'debug' => $e['message']]);
    }
});

/* === 1. CSRF (łagodny) === */
$postedCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null);
if (!empty($_SESSION['csrf'])) {
    if (!$postedCsrf || !hash_equals($_SESSION['csrf'], (string)$postedCsrf)) {
        // Łagodnie: nie przerywamy, ale można odkomentować twardy blok.
        // echo json_encode(['status'=>'error','message'=>'CSRF token invalid']); exit;
    }
}

/* === 2. Helpery lokalne === */
function getCartSid(): string
{
    if (empty($_COOKIE['cart_sid']) || !preg_match('/^[a-f0-9]{32}$/', (string)$_COOKIE['cart_sid'])) {
        $sid = bin2hex(random_bytes(16));
        setcookie('cart_sid', $sid, [
            'expires'  => time() + 60 * 60 * 24 * 30,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['cart_sid'] = $sid;
    }
    return (string)$_COOKIE['cart_sid'];
}
function productsHasCol(PDO $pdo, string $col): bool
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $cache[strtolower($c)] = true;
        }
    }
    return isset($cache[strtolower($col)]);
}
// suma paczki (order_items.quantity * order_items.price)
function recalc_group_amount(PDO $pdo, int $order_group_id): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(qty * price),0) FROM order_items WHERE order_group_id = :og");
    $stmt->execute(['og' => $order_group_id]);
    return (float)$stmt->fetchColumn();
}
// draft payment po tokenie
function upsert_payment_draft(PDO $pdo, int $order_id, int $order_group_id, string $checkout_token, float $amount, string $currency): void
{
    // Uwaga: przejściowo status PL; PaymentEngine docelowo spina ENUMy
    $stmt = $pdo->prepare("SELECT id FROM payments WHERE checkout_token = :t LIMIT 1");
    $stmt->execute(['t' => $checkout_token]);
    $pid = (int)($stmt->fetchColumn() ?: 0);
    if ($pid) {
        $stmt = $pdo->prepare("UPDATE payments SET amount = :a, currency = :cur, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['a' => $amount, 'cur' => $currency, 'id' => $pid]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO payments (order_id, order_group_id, currency, amount, status, checkout_token, created_at, updated_at)
            VALUES (:oid, :ogid, :cur, :a, 'oczekujące', :t, NOW(), NOW())
        ");
        $stmt->execute(['oid' => $order_id, 'ogid' => $order_group_id, 'cur' => $currency, 'a' => $amount, 't' => $checkout_token]);
    }
}

/* mini-koszyk + free ship (wspólne) */
function buildMiniCart(PDO $pdo, int $ownerId, array $opts, string $cdnBase, float $freeShipThreshold, string $currency): array
{
    $items = [];
    $total = 0.0;

    if (!empty($opts['order_group_id'])) {
        // zalogowany: order_items
        $st = $pdo->prepare("
            SELECT oi.product_id, oi.qty, oi.price, p.name, COALESCE(pi.image_path,'') AS image_path
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_main = 1
            WHERE oi.order_group_id = :gid
            ORDER BY oi.id DESC
            LIMIT 30
        ");
        $st->execute([':gid' => (int)$opts['order_group_id']]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $line = ((float)$row['qty']) * (float)$row['price'];
            $total += $line;
            $thumb = $row['image_path'] ? $cdnBase . '/uploads/products/' . ltrim($row['image_path'], '/') : 'https://via.placeholder.com/64?text=%20';
            $items[] = [
                'id'         => (int)$row['product_id'],
                'name'       => (string)$row['name'],
                'qty'        => (float)$row['qty'],
                'price'      => number_format((float)$row['price'], 2, ',', ' ') . ' ' . $currency,
                'line_total' => number_format($line, 2, ',', ' ') . ' ' . $currency,
                'thumb'      => $thumb
            ];
        }
    } else {
        // gość: cart_items
        $st = $pdo->prepare("
            SELECT ci.product_id, ci.qty, COALESCE(ci.unit_price, p.unit_price, 0) AS price, p.name, COALESCE(pi.image_path,'') AS image_path
            FROM cart_items ci
            JOIN products p ON p.id = ci.product_id
            LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_main = 1
            WHERE ci.owner_id = :oid AND ci.cart_sid = :sid AND ci.qty > 0
            ORDER BY COALESCE(ci.updated_at, ci.id) DESC
            LIMIT 30
        ");
        $st->execute([':oid' => $ownerId, ':sid' => (string)$opts['cart_sid']]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $line = ((float)$row['qty']) * (float)$row['price'];
            $total += $line;
            $thumb = $row['image_path'] ? $cdnBase . '/uploads/products/' . ltrim($row['image_path'], '/') : 'https://via.placeholder.com/64?text=%20';
            $items[] = [
                'id'         => (int)$row['product_id'],
                'name'       => (string)$row['name'],
                'qty'        => (float)$row['qty'],
                'price'      => number_format((float)$row['price'], 2, ',', ' ') . ' ' . $currency,
                'line_total' => number_format($line, 2, ',', ' ') . ' ' . $currency,
                'thumb'      => $thumb
            ];
        }
    }

    $missing = max(0.0, $freeShipThreshold - $total);
    $freeShipping = [
        'progress_pct'      => $freeShipThreshold > 0 ? min(100, ($total / $freeShipThreshold * 100)) : 0,
        'missing'           => $missing,
        'missing_formatted' => $missing > 0 ? number_format($missing, 2, ',', ' ') . ' ' . $currency : null
    ];

    return [
        'items'         => $items,
        'free_shipping' => $freeShipping,
        'total'         => $total,
        'sum_formatted' => number_format($total, 2, ',', ' ') . ' ' . $currency
    ];
}

/* === 3. Wejście === */
$owner_id   = (int)($_SESSION['owner_id'] ?? 1);
$client_id  = (int)($_SESSION['client_id'] ?? 0);
$product_id = (int)($_POST['product_id'] ?? 0);
$op         = (string)($_POST['op'] ?? '');
$qty_set    = (int)($_POST['qty'] ?? 0);
$token      = $_POST['checkout_token'] ?? null;

if ($product_id < 1 || !in_array($op, ['inc', 'dec', 'set', 'remove'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Złe parametry']);
    exit;
}

/* === 4. Ustawienia === */
$settings          = getShopSettings($owner_id);
$cdnBase           = rtrim($settings['cdn_url'] ?? 'https://panel.olaj.pl', '/');
$freeShipThreshold = (float)($settings['free_shipping_threshold'] ?? 0.0);
$currency          = (string)($settings['currency'] ?? 'PLN');

try {
    /* ===================== 5. ZALOGOWANY ===================== */
    if ($client_id > 0) {
        $pdo->beginTransaction();

        // 5.1 znajdź aktywną grupę (po tokenie, a jak brak — ostatnia dla klienta)
        if (!empty($token)) {
            $stmt = $pdo->prepare("
                SELECT og.id AS ogid, og.group_token, o.id AS oid, o.checkout_token
                FROM order_groups og
                JOIN orders o ON o.id = og.order_id
                WHERE og.group_token = :t AND o.client_id = :cid AND o.owner_id = :oid
                ORDER BY og.id DESC
                LIMIT 1
            ");
            $stmt->execute(['t' => $token, 'cid' => $client_id, 'oid' => $owner_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT og.id AS ogid, og.group_token, o.id AS oid, o.checkout_token
                FROM order_groups og
                JOIN orders o ON o.id = og.order_id
                WHERE o.client_id = :cid AND o.owner_id = :oid
                ORDER BY og.id DESC
                LIMIT 1
            ");
            $stmt->execute(['cid' => $client_id, 'oid' => $owner_id]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Brak aktywnej paczki']);
            exit;
        }
        $order_group_id = (int)$row['ogid'];
        $group_token    = (string)$row['group_token'];
        $checkout_token = (string)$row['checkout_token'];
        $order_id       = (int)$row['oid'];

        // 5.2 bieżąca pozycja
        $stmt = $pdo->prepare("SELECT id, qty, price FROM order_items WHERE order_group_id = :og AND product_id = :pid LIMIT 1");
        $stmt->execute(['og' => $order_group_id, 'pid' => $product_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        // 5.3 cena produktu (fallback, jeśli nowa pozycja)
        $statusCond = productsHasCol($pdo, 'is_active') ? 'is_active = 1' : (productsHasCol($pdo, 'active') ? 'active = 1' : '1=1');
        $unit_price = null;

        $new_qty = 0;
        $removed = false;

        if ($op === 'inc') {
            if ($item) {
                $new_qty = (int)$item['qty'] + 1;
                $stmt = $pdo->prepare("UPDATE order_items SET qty = :q, updated_at = NOW() WHERE id = :id");
                $stmt->execute(['q' => $new_qty, 'id' => $item['id']]);
                $unit_price = (float)$item['price'];
            } else {
                $stmtP = $pdo->prepare("SELECT COALESCE(unit_price, 0) FROM products WHERE id = :pid AND owner_id = :oid AND {$statusCond}");
                $stmtP->execute(['pid' => $product_id, 'oid' => $owner_id]);
                $unit_price = (float)$stmtP->fetchColumn();
                if ($unit_price <= 0) throw new \Exception('Produkt nieaktywny');
                $stmt = $pdo->prepare("
                    INSERT INTO order_items (order_group_id, product_id, qty, price, source, created_at, updated_at)
                    VALUES (:og, :pid, 1, :unit, 'sklep', NOW(), NOW())
                ");
                $stmt->execute(['og' => $order_group_id, 'pid' => $product_id, 'unit' => $unit_price]);
                $new_qty = 1;
            }
        } elseif ($op === 'dec') {
            if (!$item) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Pozycja nie istnieje']);
                exit;
            }
            $new_qty   = max(1, (int)$item['qty'] - 1);
            $unit_price = (float)$item['price'];
            $stmt = $pdo->prepare("UPDATE order_items SET qty = :q, updated_at = NOW() WHERE id = :id");
            $stmt->execute(['q' => $new_qty, 'id' => $item['id']]);
        } elseif ($op === 'set') {
            $new_qty = max(0, $qty_set);
            if ($item) {
                if ($new_qty === 0) {
                    $stmt = $pdo->prepare("DELETE FROM order_items WHERE id = :id");
                    $stmt->execute(['id' => $item['id']]);
                    $removed = true;
                } else {
                    $unit_price = (float)$item['price'];
                    $stmt = $pdo->prepare("UPDATE order_items SET qty = :q, updated_at = NOW() WHERE id = :id");
                    $stmt->execute(['q' => $new_qty, 'id' => $item['id']]);
                }
            } else {
                if ($new_qty === 0) {
                    $pdo->commit();
                    echo json_encode([
                        'status' => 'ok',
                        'qty' => 0,
                        'removed' => true,
                        'mini' => ['items' => [], 'free_shipping' => ['progress_pct' => 0, 'missing' => 0, 'missing_formatted' => null]]
                    ]);
                    exit;
                }
                $stmtP = $pdo->prepare("SELECT COALESCE(unit_price, 0) FROM products WHERE id = :pid AND owner_id = :oid AND {$statusCond}");
                $stmtP->execute(['pid' => $product_id, 'oid' => $owner_id]);
                $unit_price = (float)$stmtP->fetchColumn();
                if ($unit_price <= 0) throw new \Exception('Produkt nieaktywny');
                $stmt = $pdo->prepare("
                    INSERT INTO order_items (order_group_id, product_id, qty, price, source, created_at, updated_at)
                    VALUES (:og, :pid, :q, :unit, 'sklep', NOW(), NOW())
                ");
                $stmt->execute(['og' => $order_group_id, 'pid' => $product_id, 'q' => $new_qty, 'unit' => $unit_price]);
            }
        } else { // remove
            if ($item) {
                $stmt = $pdo->prepare("DELETE FROM order_items WHERE id = :id");
                $stmt->execute(['id' => $item['id']]);
            }
            $new_qty = 0;
            $removed = true;
        }

        // 5.4 przelicz total + draft payment
        $group_total = recalc_group_amount($pdo, $order_group_id);
        upsert_payment_draft($pdo, $order_id, $order_group_id, $checkout_token, $group_total, $currency);

        $pdo->commit();

        // 5.5 line_total dla UI
        if ($removed) {
            $line_total = 0.0;
        } else {
            if ($unit_price === null) {
                $stmtU = $pdo->prepare("SELECT price FROM order_items WHERE order_group_id = :og AND product_id = :pid LIMIT 1");
                $stmtU->execute(['og' => $order_group_id, 'pid' => $product_id]);
                $unit_price = (float)$stmtU->fetchColumn();
            }
            $line_total = $new_qty * ((float)$unit_price);
        }

        // 5.6 mini + free
        $mini = buildMiniCart($pdo, $owner_id, ['order_group_id' => $order_group_id], $cdnBase, $freeShipThreshold, $currency);

        echo json_encode([
            'status'         => 'ok',
            'qty'            => $new_qty,
            'removed'        => $removed,
            'line_total'     => $line_total,
            'group_total'    => $group_total,
            'checkout_token' => $checkout_token,
            'mini'           => $mini,
            'free_shipping'  => $mini['free_shipping']
        ]);
        exit;
    }

    /* ===================== 6. GOŚĆ (cart_items via CartEngine) ===================== */
    $sid = getCartSid();

    // dla operacji 'inc' i wstawień potrzebujemy ceny (CartEngine nie liczy cen)
    $statusCond = productsHasCol($pdo, 'is_active') ? 'is_active = 1' : (productsHasCol($pdo, 'active') ? 'active = 1' : '1=1');
    $fetchUnitPrice = function (int $pid) use ($pdo, $owner_id, $statusCond): float {
        $stmt = $pdo->prepare("SELECT COALESCE(unit_price, 0) FROM products WHERE id = :pid AND owner_id = :oid AND {$statusCond}");
        $stmt->execute(['pid' => $pid, 'oid' => $owner_id]);
        return (float)$stmt->fetchColumn();
    };

    $removed    = false;
    $new_qty    = 0.0;
    $unit_price = 0.0;

    // Pobierz aktualny wiersz (do dec/set/remove)
    $stmt = $pdo->prepare("
        SELECT id, qty, unit_price
        FROM cart_items
        WHERE owner_id = :oid AND cart_sid = :sid AND product_id = :pid
        LIMIT 1
    ");
    $stmt->execute(['oid' => $owner_id, 'sid' => $sid, 'pid' => $product_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Mapowanie operacji przez CartEngine
    if ($op === 'inc') {
        // zawsze dostarcz cenę — CartEngine tylko zwiększa qty przy duplikacie
        $unit_price = $fetchUnitPrice($product_id);
        if ($unit_price <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Produkt nieaktywny']);
            exit;
        }
        CartEngine::addItem($pdo, $owner_id, $sid, $product_id, 1.0, $unit_price, null, 'shop');
        // nowa ilość:
        $stmt = $pdo->prepare("SELECT qty, unit_price FROM cart_items WHERE owner_id = :oid AND cart_sid = :sid AND product_id = :pid");
        $stmt->execute(['oid' => $owner_id, 'sid' => $sid, 'pid' => $product_id]);
        [$new_qty, $unit_price] = array_map('floatval', $stmt->fetch(PDO::FETCH_NUM) ?: [0, $unit_price]);
    } elseif ($op === 'dec') {
        if (!$row) {
            echo json_encode(['status' => 'error', 'message' => 'Pozycja nie istnieje']);
            exit;
        }
        $unit_price = (float)$row['unit_price'];
        $currentQty = (float)$row['qty'];
        $new_qty = max(0.0, $currentQty - 1.0);
        if ($new_qty <= 0.0) {
            CartEngine::removeItem($pdo, $owner_id, $sid, $product_id);
            $removed = true;
        } else {
            CartEngine::updateQuantity($pdo, $owner_id, $sid, $product_id, $new_qty);
        }
    } elseif ($op === 'set') {
        $target = max(0.0, (float)$qty_set);
        if ($target <= 0.0) {
            if ($row) {
                CartEngine::removeItem($pdo, $owner_id, $sid, $product_id);
                $removed = true;
            }
            $new_qty = 0.0;
        } else {
            if ($row) {
                $unit_price = (float)$row['unit_price'];
                CartEngine::updateQuantity($pdo, $owner_id, $sid, $product_id, $target);
            } else {
                $unit_price = $fetchUnitPrice($product_id);
                if ($unit_price <= 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Produkt nieaktywny']);
                    exit;
                }
                CartEngine::addItem($pdo, $owner_id, $sid, $product_id, $target, $unit_price, null, 'shop');
            }
            $new_qty = $target;
        }
    } else { // remove
        if ($row) {
            CartEngine::removeItem($pdo, $owner_id, $sid, $product_id);
        }
        $new_qty = 0.0;
        $removed = true;
    }

    // 6.3 line_total + suma koszyka
    if (!$removed) {
        if ($unit_price <= 0.0) {
            $stmtU = $pdo->prepare("SELECT unit_price FROM cart_items WHERE owner_id = :oid AND cart_sid = :sid AND product_id = :pid LIMIT 1");
            $stmtU->execute(['oid' => $owner_id, 'sid' => $sid, 'pid' => $product_id]);
            $unit_price = (float)($stmtU->fetchColumn() ?: 0.0);
        }
    }
    $line_total = $removed ? 0.0 : ((float)$new_qty) * ((float)$unit_price);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(qty * unit_price), 0) FROM cart_items WHERE owner_id = :oid AND cart_sid = :sid");
    $stmt->execute(['oid' => $owner_id, 'sid' => $sid]);
    $cart_total = (float)$stmt->fetchColumn();

    // 6.4 mini + free
    $mini = buildMiniCart($pdo, $owner_id, ['cart_sid' => $sid], $cdnBase, $freeShipThreshold, $currency);

    echo json_encode([
        'status'        => 'ok',
        'qty'           => $new_qty,
        'removed'       => $removed,
        'line_total'    => $line_total,
        'cart_total'    => $cart_total,
        'mini'          => $mini,
        'free_shipping' => $mini['free_shipping']
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Błąd serwera', 'debug' => $e->getMessage()]);
}
