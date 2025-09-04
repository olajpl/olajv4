<?php
// WYMAGANE ZMIENNE WEJŚCIOWE: $pdo, $owner_id, $isLoggedIn, $clientId, $settings

/* cart_sid (gość) */
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
if (empty($_COOKIE['cart_sid']) || !preg_match('/^[a-f0-9]{32}$/', (string)$_COOKIE['cart_sid'])) {
    $sid = bin2hex(random_bytes(16));
    setcookie('cart_sid', $sid, [
        'expires'  => time() + 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['cart_sid'] = $sid;
}
$cartSid = (string)($_COOKIE['cart_sid'] ?? '');

/* Dostęp tylko dla zalogowanych? */
if (!$isLoggedIn && !empty($settings['require_token_to_view'])) {
    http_response_code(403);
    exit('Dostęp tylko dla zalogowanych klientów.');
}

/* Helper: kolumna w products */
function prodHas(PDO $pdo, string $col): bool
{
    static $cols = null;
    if ($cols === null) {
        $cols = [];
        $q = $pdo->query("SHOW COLUMNS FROM products");
        if ($q) foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $cols[strtolower($r['Field'])] = true;
    }
    return isset($cols[strtolower($col)]);
}

/* Migracja $_SESSION['cart'] -> cart_items */
$getProdStmt = $pdo->prepare("
    SELECT COALESCE(unit_price,0) AS price, " . (prodHas($pdo, 'weight_kg') ? 'weight_kg' : 'NULL') . " AS weight_kg
    FROM products WHERE id=? AND owner_id=? LIMIT 1
");
function upsertCartItem(PDO $pdo, array $row): void
{
    $where = 'owner_id=:o AND product_id=:p AND ' . ($row['client_id'] ? 'client_id=:cid' : 'cart_sid=:sid');
    $params = [
        ':o' => (int)$row['owner_id'],
        ':p' => (int)$row['product_id'],
        ':qty' => (float)$row['qty'],
        ':up' => isset($row['unit_price']) ? (float)$row['unit_price'] : null,
        ':w' => isset($row['weight_kg']) ? $row['weight_kg'] : null,
    ];
    if ($row['client_id']) $params[':cid'] = (int)$row['client_id'];
    else $params[':sid'] = (string)$row['cart_sid'];
    $st = $pdo->prepare("UPDATE cart_items SET qty=qty+:qty, unit_price=COALESCE(:up,unit_price), updated_at=NOW() WHERE $where");
    $st->execute($params);
    if ($st->rowCount() > 0) return;
    $st2 = $pdo->prepare("INSERT INTO cart_items (owner_id,product_id," . ($row['client_id'] ? 'client_id' : 'cart_sid') . ",qty,unit_price,source,created_at)
                        VALUES (:o,:p," . ($row['client_id'] ? ':cid' : ':sid') . ",:qty,:up,'shop',NOW())");
    $st2->execute($params);
}
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        if ($pid < 1) continue;
        $qty = max(1, (int)($it['quantity'] ?? $it['qty'] ?? 1));
        $getProdStmt->execute([$pid, $owner_id]);
        $snap = $getProdStmt->fetch(PDO::FETCH_ASSOC) ?: ['price' => 0, 'weight_kg' => null];
        upsertCartItem($pdo, [
            'owner_id' => $owner_id,
            'product_id' => $pid,
            'client_id' => $isLoggedIn ? $clientId : 0,
            'cart_sid' => $isLoggedIn ? null : (string)$cartSid,
            'qty' => (float)$qty,
            'unit_price' => isset($it['unit_price']) ? (float)$it['unit_price'] : (float)$snap['price'],
            'weight_kg' => isset($it['weight_kg']) ? (float)$it['weight_kg'] : ($snap['weight_kg'] ?? null),
        ]);
    }
    unset($_SESSION['cart']);
}

/* Konfiguracja UI */
$cdn        = rtrim($settings['cdn_url'] ?? 'https://panel.olaj.pl', '/');
$themeColor = $settings['theme_color'] ?? '#ec4899';
$font       = $settings['font_family'] ?? 'sans-serif';
$currency   = $settings['currency'] ?? 'PLN';
$logoPath   = !empty($settings['logo_path']) ? (string)$settings['logo_path'] : '';
$welcomeMsg = $settings['welcome_message'] ?? '';
$showWeight = !empty($settings['show_weight_info']);
$soundOnAdd = (int)($settings['sound_on_add_to_cart'] ?? 0);

$rawMarquee = trim($settings['marquee_text'] ?? '');
$marqueeItems = array_values(array_filter(
    preg_split("/(\r\n|\n|\|\|)/", $rawMarquee),
    fn($s) => trim((string)$s) !== ''
));

/* Produkty */
$statusCond = prodHas($pdo, 'is_active') ? 'p.is_active=1' : (prodHas($pdo, 'active') ? 'p.active=1' : '1=1');
$stockExpr  = prodHas($pdo, 'stock_available') ? 'p.stock_available'
    : (prodHas($pdo, 'stock_cached') && prodHas($pdo, 'stock_reserved_cached') ? '(p.stock_cached-COALESCE(p.stock_reserved_cached,0))'
        : (prodHas($pdo, 'stock_cached') ? 'p.stock_cached' : '0'));
$imgJoin    = "LEFT JOIN product_images pi ON pi.product_id=p.id AND pi.is_main=1";

$stmt = $pdo->prepare("
  SELECT p.id, p.name, COALESCE(p.unit_price,0) AS price, {$stockExpr} AS stock,
         " . (prodHas($pdo, 'weight_kg') ? 'p.weight_kg' : 'NULL') . " AS weight_kg,
         COALESCE(pi.image_path,'') AS image_path
  FROM products p
  {$imgJoin}
  WHERE p.owner_id=? AND {$statusCond}
  ORDER BY p.id DESC
");
$stmt->execute([$owner_id]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* Mini-koszyk */
$miniItems = [];
$miniQ = 0;
if ($isLoggedIn) {
    $params = [':oid' => $owner_id, ':cid' => $clientId];
    $q = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM cart_items WHERE owner_id=:oid AND client_id=:cid AND qty>0");
    $q->execute($params);
    $miniQ = (int)$q->fetchColumn();
    $qi = $pdo->prepare("
      SELECT c.product_id AS id, p.name, c.qty AS qty,
             COALESCE(c.unit_price,p.unit_price,0) AS price,
             COALESCE(pi.image_path,'') AS image_path
      FROM cart_items c
      LEFT JOIN products p ON p.id=c.product_id
      LEFT JOIN product_images pi ON pi.product_id=p.id AND pi.is_main=1
      WHERE c.owner_id=:oid AND c.client_id=:cid AND c.qty>0
      ORDER BY c.id DESC
    ");
    $qi->execute($params);
    $miniItems = $qi->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    $params = [':oid' => $owner_id, ':sid' => $cartSid];
    $q = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM cart_items WHERE owner_id=:oid AND cart_sid=:sid AND qty>0");
    $q->execute($params);
    $miniQ = (int)$q->fetchColumn();
    $qi = $pdo->prepare("
      SELECT c.product_id AS id, p.name, c.qty AS qty,
             COALESCE(c.unit_price,p.unit_price,0) AS price,
             COALESCE(pi.image_path,'') AS image_path
      FROM cart_items c
      LEFT JOIN products p ON p.id=c.product_id
      LEFT JOIN product_images pi ON pi.product_id=p.id AND pi.is_main=1
      WHERE c.owner_id=:oid AND c.cart_sid=:sid AND c.qty>0
      ORDER BY c.id DESC
    ");
    $qi->execute($params);
    $miniItems = $qi->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$miniTotal = 0.0;
foreach ($miniItems as $it) $miniTotal += (float)$it['price'] * (float)$it['qty'];

/* Darmowa dostawa (SSR) */
$freeShipThreshold = (float)($settings['free_shipping_threshold'] ?? 0);
$freeShipMissing = max(0.0, $freeShipThreshold - $miniTotal);
$freeShipProgress = $freeShipThreshold > 0 ? min(100.0, round(($miniTotal / $freeShipThreshold) * 100, 2)) : 0.0;
$freeShipTextSSR = $freeShipThreshold > 0 ? ($freeShipMissing > 0
    ? 'Brakuje ' . number_format($freeShipMissing, 2, ',', ' ') . ' ' . $currency . ' do darmowej dostawy'
    : 'Darmowa dostawa!') : '';

$FREE_SHIP = [
    'threshold' => $freeShipThreshold,
    'progress_pct' => $freeShipProgress,
    'missing' => $freeShipMissing,
    'missing_formatted' => $freeShipThreshold > 0 ? number_format($freeShipMissing, 2, ',', ' ') . ' ' . $currency : null,
    'text' => $freeShipTextSSR,
];

/* LIVE (opcjonalnie) */
$active_live_id = 0;
try {
    $st = $pdo->prepare("SELECT id FROM live_streams WHERE owner_id=:oid AND status='live' ORDER BY started_at DESC LIMIT 1");
    $st->execute([':oid' => $owner_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) $active_live_id = (int)$row['id'];
} catch (Throwable $e) { /* brak LIVE nie blokuje */
}

/* Zmiennie dla topbara / konta */
$clientName  = $client['name'] ?? '';
$clientTokenLink = $_SESSION['client_token'] ?? '';
