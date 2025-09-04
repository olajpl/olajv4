<?php
// includes/cart_common.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php'; // zapewnia ensureClient()

header('Content-Type: application/json; charset=UTF-8');

function getCartSessionId(): string
{
    if (!isset($_COOKIE['cart_sid']) || !preg_match('/^[a-f0-9]{32}$/', (string)$_COOKIE['cart_sid'])) {
        $sid = bin2hex(random_bytes(16));
        setcookie('cart_sid', $sid, time() + 60 * 60 * 24 * 30, '/', '', isset($_SERVER['HTTPS']), true);
        $_COOKIE['cart_sid'] = $sid;
    }
    return (string)$_COOKIE['cart_sid'];
}

function resolveCheckoutContext(PDO $pdo, array $client = null, ?string $forcedToken = null): ?array
{
    if (!$client) return null;
    $tok = $forcedToken ?? ($_POST['checkout_token'] ?? ($_SESSION['checkout_token'] ?? ''));
    if ($tok === '') return null;
    $q = $pdo->prepare("
    SELECT o.id AS order_id, og.id AS order_group_id, og.checkout_token
    FROM order_groups og
    JOIN orders o ON o.id = og.order_id
    WHERE og.checkout_token = :t AND o.client_id = :cid AND o.owner_id = :oid
    LIMIT 1
  ");
    $q->execute(['t' => $tok, 'cid' => (int)$client['id'], 'oid' => (int)$client['owner_id']]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function productSnapshot(PDO $pdo, int $ownerId, int $productId): ?array
{
    $s = $pdo->prepare("SELECT price AS unit_price, weight AS weight_kg FROM products WHERE id=:p AND owner_id=:o AND active=1");
    $s->execute(['p' => $productId, 'o' => $ownerId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function recalcGroupAmount(PDO $pdo, int $groupId): float
{
    $s = $pdo->prepare("SELECT COALESCE(SUM(quantity * unit_price),0) FROM order_items WHERE order_group_id=:g");
    $s->execute(['g' => $groupId]);
    return (float)$s->fetchColumn();
}

function upsertPaymentDraft(PDO $pdo, int $orderId, int $groupId, string $checkoutToken, float $amount): void
{
    $s = $pdo->prepare("SELECT id FROM payments WHERE checkout_token=:t LIMIT 1");
    $s->execute(['t' => $checkoutToken]);
    $pid = (int)($s->fetchColumn() ?: 0);
    if ($pid) {
        $u = $pdo->prepare("UPDATE payments SET amount=:a, currency='PLN', status=IF(status='brak płatności','oczekujące',status), updated_at=NOW() WHERE id=:id");
        $u->execute(['a' => $amount, 'id' => $pid]);
    } else {
        $i = $pdo->prepare("
      INSERT INTO payments (order_id, order_group_id, checkout_token, currency, amount, status, created_at)
      VALUES (:oid, :gid, :tok, 'PLN', :amt, 'oczekujące', NOW())
    ");
        $i->execute(['oid' => $orderId, 'gid' => $groupId, 'tok' => $checkoutToken, 'amt' => $amount]);
    }
}

/** Mini-koszyk do UI (możesz dopasować format) */
function buildMiniCart(PDO $pdo, ?array $ctx, ?int $ownerId, ?string $sid): array
{
    if ($ctx) {
        $st = $pdo->prepare("
      SELECT oi.product_id id, p.name, oi.quantity qty, oi.unit_price, 
             COALESCE(pi.image_path,'') AS img
      FROM order_items oi
      LEFT JOIN products p ON p.id = oi.product_id
      LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_main = 1
      WHERE oi.order_group_id = :g
      ORDER BY oi.id DESC
    ");
        $st->execute(['g' => (int)$ctx['order_group_id']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // gość
        $st = $pdo->prepare("
      SELECT ci.product_id id, p.name, ci.quantity qty, ci.unit_price,
             COALESCE(pi.image_path,'') AS img
      FROM cart_items ci
      LEFT JOIN products p ON p.id = ci.product_id
      LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_main = 1
      WHERE ci.owner_id = :o AND ci.session_id = :s
      ORDER BY ci.id DESC
    ");
        $st->execute(['o' => $ownerId, 's' => $sid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $items = [];
    $sum = 0.0;
    foreach ($rows as $r) {
        $lt = (int)$r['qty'] * (float)$r['unit_price'];
        $sum += $lt;
        $img = $r['img'] ? (getShopCdn() . '/uploads/products/' . ltrim($r['img'], '/')) : 'https://via.placeholder.com/120x90?text=Brak';
        $items[] = [
            'id' => (int)$r['id'],
            'name' => (string)$r['name'],
            'qty' => (int)$r['qty'],
            'price' => number_format((float)$r['unit_price'], 2, ',', ' ') . " zł",
            'line_total' => number_format($lt, 2, ',', ' ') . " zł",
            'thumb' => $img,
        ];
    }
    // free shipping kalkulacja (przykład: próg 200 zł)
    $threshold = 200.00;
    $missing = max(0, $threshold - $sum);
    return [
        'items' => $items,
        'sum'   => number_format($sum, 2, ',', ' ') . " zł",
        'free_shipping' => [
            'threshold' => $threshold,
            'missing' => $missing,
            'missing_formatted' => number_format($missing, 2, ',', ' ') . " zł",
            'progress_pct' => $threshold > 0 ? min(100, round(($sum / $threshold) * 100)) : 0
        ]
    ];
}

function getShopCdn(): string
{
    // fallback; jeśli masz $settings — możesz to przepiąć
    return 'https://panel.olaj.pl';
}
