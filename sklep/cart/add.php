<?php

declare(strict_types=1);
session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
if (is_file(__DIR__ . '/../includes/log.php')) {
    require_once __DIR__ . '/../includes/log.php'; // olaj_v4_logger
}
if (!function_exists('wlog')) {
    function wlog(string $m, array $c = []): void {}
}

require_once __DIR__ . '/../engine/Shipping/ShippingEngine.php';

use Engine\Shipping\ShippingEngine;

header('Content-Type: application/json; charset=utf-8');

/* ---------- CSRF (miękko) ---------- */
$postedCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null);
if (!empty($_SESSION['csrf']) && (!$postedCsrf || !hash_equals($_SESSION['csrf'], (string)$postedCsrf))) {
    // echo json_encode(['status'=>'error','message'=>'CSRF token invalid']); exit;
}

$ownerId   = (int)($_SESSION['owner_id'] ?? 1);
$productId = (int)($_POST['product_id'] ?? 0);
$quantity  = max(1, (int)($_POST['quantity'] ?? 1));
if ($productId < 1) {
    echo json_encode(['status' => 'error', 'message' => 'Nieprawidłowe ID produktu']);
    exit;
}

$settings  = getShopSettings($ownerId);
$cdnBase   = rtrim($settings['cdn_url'] ?? 'https://panel.olaj.pl', '/');
$currency  = (string)($settings['currency'] ?? 'PLN');
$freeShipThreshold = (float)($settings['free_shipping_threshold'] ?? 0.0);

function generateCheckoutToken(): string
{
    $raw = bin2hex(random_bytes(16));
    return vsprintf('%s-%s-%s-%s-%s-%s-%s-%s', str_split($raw, 4));
}
function getCartSessionId(): string
{
    if (empty($_COOKIE['cart_sid']) || !preg_match('/^[a-f0-9]{32}$/', (string)$_COOKIE['cart_sid'])) {
        $sid = bin2hex(random_bytes(16));
        setcookie('cart_sid', $sid, [
            'expires' => time() + 60 * 60 * 24 * 30,
            'path'   => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['cart_sid'] = $sid;
    }
    return (string)$_COOKIE['cart_sid'];
}
function fmt_money(float $v, string $currency): string
{
    return number_format($v, 2, ',', ' ') . ' ' . $currency;
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

$statusCond = productsHasCol($pdo, 'is_active') ? 'p.is_active=1' : (productsHasCol($pdo, 'active') ? 'p.active=1' : '1=1');
$prodStmt = $pdo->prepare("
  SELECT p.id, p.name, COALESCE(p.unit_price,0) AS price, COALESCE(p.weight_kg,0) AS weight_kg, COALESCE(pi.image_path,'') AS image_path
  FROM products p
  LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_main = 1
  WHERE p.id=:pid AND p.owner_id=:oid AND {$statusCond}
  LIMIT 1
");
$prodStmt->execute([':pid' => $productId, ':oid' => $ownerId]);
$product = $prodStmt->fetch(PDO::FETCH_ASSOC);
if (!$product) {
    echo json_encode(['status' => 'error', 'message' => 'Produkt nie istnieje']);
    exit;
}

$unitPrice = (float)$product['price'];

if (!empty($_SESSION['client_id'])) {
    $clientId    = (int)$_SESSION['client_id'];
    $pdo->beginTransaction();
    try {
        $qo = $pdo->prepare("
          SELECT id, order_status
          FROM orders
          WHERE client_id=:cid AND owner_id=:oid
            AND order_status IN ('nowe','otwarta_paczka','otwarta_paczka:add_products')
          ORDER BY id DESC
          LIMIT 1
        ");
        $qo->execute([':cid' => $clientId, ':oid' => $ownerId]);
        $ord = $qo->fetch(PDO::FETCH_ASSOC) ?: null;

        $targetOrderId = null;
        $targetGroupId = null;
        $checkoutToken = null;

        if ($ord) {
            $targetOrderId = (int)$ord['id'];
            if ((string)$ord['order_status'] === 'nowe') {
                $gq = $pdo->prepare("SELECT id, checkout_token FROM order_groups WHERE order_id=:oid ORDER BY id ASC LIMIT 1");
                $gq->execute([':oid' => $targetOrderId]);
                $g = $gq->fetch(PDO::FETCH_ASSOC);
                if ($g) {
                    $targetGroupId = (int)$g['id'];
                    $checkoutToken = (string)$g['checkout_token'];
                } else {
                    $checkoutToken = generateCheckoutToken();
                    $pdo->prepare("INSERT INTO order_groups (order_id, group_token, checkout_token, created_at) VALUES (:oid,:tok,:chk,NOW())")
                        ->execute([':oid' => $targetOrderId, ':tok' => $checkoutToken, ':chk' => $checkoutToken]);


                    $targetGroupId = (int)$pdo->lastInsertId();
                }
            } else {
                $checkoutToken = generateCheckoutToken();
                $pdo->prepare("INSERT INTO order_groups (order_id, group_token, checkout_token, created_at) VALUES (:oid,:tok,:chk,NOW())")
                    ->execute([':oid' => $targetOrderId, ':tok' => $checkoutToken, ':chk' => $checkoutToken]);


                $targetGroupId = (int)$pdo->lastInsertId();
            }
        } else {
            $pdo->prepare("INSERT INTO orders (client_id, owner_id, order_status, created_at) VALUES (:cid,:oid,'nowe',NOW())")
                ->execute([':cid' => $clientId, ':oid' => $ownerId]);
            $targetOrderId = (int)$pdo->lastInsertId();
            $checkoutToken = generateCheckoutToken();
            $pdo->prepare("INSERT INTO order_groups (order_id, group_token, checkout_token, created_at) VALUES (:oid,:tok,:chk,NOW())")
                ->execute([':oid' => $targetOrderId, ':tok' => $checkoutToken, ':chk' => $checkoutToken]);


            $targetGroupId = (int)$pdo->lastInsertId();
        }

        $ins = $pdo->prepare("
    INSERT INTO order_items 
    (owner_id, order_id, order_group_id, product_id, name, sku, qty, unit_price, vat_rate, source_type, source_channel, created_at)
    VALUES 
    (:owner_id, :order_id, :group_id, :product_id, :name, :sku, :qty, :unit_price, :vat_rate, :source_type, :source_channel, NOW())
    ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
");

        $ins->execute([
            ':owner_id'       => $ownerId,
            ':order_id'       => $targetOrderId,
            ':group_id'       => $targetGroupId,
            ':product_id'     => $productId,
            ':name'           => $product['name'],
            ':sku'            => null,
            ':qty'            => $quantity,
            ':unit_price'     => $unitPrice,
            ':vat_rate'       => 23.00,
            ':source_type'    => 'shop',
            ':source_channel' => 'shop',
        ]);


        $sumQ = $pdo->prepare("SELECT COALESCE(SUM(quantity * price),0) FROM order_items WHERE order_group_id=:gid");
        $sumQ->execute([':gid' => $targetGroupId]);
        $amount = (float)$sumQ->fetchColumn();

        $pay = $pdo->prepare("
    INSERT INTO payments (order_id, order_group_id, amount, currency, status, created_at)
    VALUES (:oid, :gid, :amt, :cur, 'draft', NOW())
    ON DUPLICATE KEY UPDATE amount = VALUES(amount)
");
        $pay->execute([
            ':oid' => $targetOrderId,
            ':gid' => $targetGroupId,
            ':amt' => $amount,
            ':cur' => $currency,
        ]);


        wlog('cart.add.target', ['owner' => $ownerId, 'client' => $clientId, 'order' => $targetOrderId, 'group' => $targetGroupId]);

        // Emit signal
        ShippingEngine::emitWeightBracketSignals($pdo, $targetGroupId, $ownerId, $clientId);

        $pdo->commit();

        $mini = buildMiniCart($pdo, $ownerId, ['order_group_id' => $targetGroupId], $cdnBase, $freeShipThreshold, $currency);

        echo json_encode([
            'status' => 'ok',
            'message' => 'Dodano produkt do zamówienia',
            'checkout_token' => $checkoutToken,
            'order_id' => $targetOrderId,
            'mini' => $mini,
            'free_shipping' => $mini['free_shipping']
        ]);
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        wlog('cart.add.error', ['err' => $e->getMessage()]);
        echo json_encode(['status' => 'error', 'message' => 'Błąd dodawania: ' . $e->getMessage()]);

        exit;
    }
}

$cartSid = getCartSessionId();
$upd = $pdo->prepare("
  UPDATE cart_items
  SET qty = qty + :q,
      unit_price = COALESCE(:price, unit_price),
      updated_at = NOW()
  WHERE owner_id=:o AND cart_sid=:sid AND product_id=:p
");
$ok = $upd->execute([':q' => $quantity, ':price' => $unitPrice, ':o' => $ownerId, ':sid' => $cartSid, ':p' => $productId]);
wlog('cart.add.guest', [
    'owner' => $ownerId,
    'sid' => $cartSid,
    'product' => $productId,
    'qty' => $quantity,
    'unit_price' => $unitPrice,
]);
if ($ok && $upd->rowCount() === 0) {
    try {
        $ins = $pdo->prepare("
          INSERT INTO cart_items (owner_id, product_id, cart_sid, qty, unit_price, source, created_at, updated_at)
VALUES (:o,:p,:sid,:q,:price,'shop',NOW(),NOW())

        ");
        $ins->execute([
            ':o' => $ownerId,
            ':p' => $productId,
            ':sid' => $cartSid,
            ':q' => $quantity,
            ':price' => $unitPrice
        ]);
    } catch (Throwable $e) {
        wlog('cart.add.insert_error', ['error' => $e->getMessage()]);
        echo json_encode(['status' => 'error', 'message' => 'Błąd dodawania do koszyka: ' . $e->getMessage()]);
        exit;
    }
}


$mini = buildMiniCart($pdo, $ownerId, ['cart_sid' => $cartSid], $cdnBase, $freeShipThreshold, $currency);

echo json_encode(['status' => 'ok', 'message' => 'Dodano do koszyka', 'mini' => $mini, 'free_shipping' => $mini['free_shipping']]);
function buildMiniCart(PDO $pdo, int $ownerId, array $where, string $cdnBase, float $freeShippingThreshold, string $currency): array
{
    $params = $where;
    $conds = [];
    if (isset($where['order_group_id'])) {
        $conds[] = 'oi.order_group_id = :order_group_id';
        $table = 'order_items oi';
        $join = 'LEFT JOIN products p ON p.id = oi.product_id LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_main = 1';
        $selectQty = 'oi.quantity';
        $selectPrice = 'oi.price';
    } else {
        $conds[] = 'ci.cart_sid = :cart_sid AND ci.owner_id = :owner_id';
        $table = 'cart_items ci';
        $join = 'LEFT JOIN products p ON p.id = ci.product_id LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_main = 1';
        $selectQty = 'ci.qty';
        $selectPrice = 'ci.unit_price';
        $params['owner_id'] = $ownerId;
    }

    $sql = "
      SELECT p.id, p.name, {$selectQty} AS qty, {$selectPrice} AS price,
             COALESCE(pi.image_path, '') AS image_path
      FROM {$table}
      {$join}
      WHERE " . implode(' AND ', $conds) . "
      ORDER BY p.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    $sum = 0.0;
    $count = 0;

    foreach ($rows as $row) {
        $qty = (float)$row['qty'];
        $price = (float)$row['price'];
        $lineTotal = $qty * $price;
        $sum += $lineTotal;
        $count += $qty;

        $items[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'qty' => $qty,
            'price' => number_format($price, 2, ',', ' ') . ' ' . $currency,
            'line_total' => number_format($lineTotal, 2, ',', ' ') . ' ' . $currency,
            'thumb' => $row['image_path']
                ? $cdnBase . '/uploads/products/' . ltrim($row['image_path'], '/')
                : 'https://via.placeholder.com/64?text=%20'
        ];
    }

    return [
        'items' => $items,
        'count' => $count,
        'sum_formatted' => number_format($sum, 2, ',', ' ') . ' ' . $currency,
        'free_shipping' => [
            'threshold' => $freeShippingThreshold,
            'progress_pct' => $freeShippingThreshold > 0 ? round(min(100, 100 * $sum / $freeShippingThreshold)) : 0,
            'missing_formatted' => $freeShippingThreshold > $sum
                ? number_format($freeShippingThreshold - $sum, 2, ',', ' ') . ' ' . $currency
                : null
        ]
    ];
}
