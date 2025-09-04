<?php
// cart/remove.php – usuwanie produktu z koszyka/PGZ (V4-ready)
// 1) Bootstrap + CSRF
// 2) Ustalenie kontekstu (owner_id, client/session)
// 3) Zalogowany -> remove z order_items (ostatnia grupa 'nowe'), dodatkowo czyścimy ewentualny cart_items (porządek)
// 4) Gość -> remove z cart_items po session_id (cart_sid)
// 5) Zwracamy JSON: status, mini (items), free_shipping

declare(strict_types=1);
session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

// ---------- [1] CSRF (łagodnie – jak brak, nie zabijamy, ale możesz zaostrzyć) ----------
$postedCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null);
if (!empty($_SESSION['csrf'])) {
  if (!$postedCsrf || !hash_equals($_SESSION['csrf'], (string)$postedCsrf)) {
    // echo json_encode(['status'=>'error','message'=>'CSRF token invalid']); exit;
  }
}

// ---------- [2] Wejście ----------
$ownerId   = (int)($_SESSION['owner_id'] ?? 1);
$productId = (int)($_POST['product_id'] ?? 0);
if ($productId < 1) {
  echo json_encode(['status' => 'error', 'message' => 'Nieprawidłowy produkt']);
  exit;
}

// Settings (cdn, free shipping threshold)
$settings = getShopSettings($ownerId);
$cdnBase  = rtrim($settings['cdn_url'] ?? 'https://panel.olaj.pl', '/');
$freeShipThreshold = (float)($settings['free_shipping_threshold'] ?? 0.0);

// cart_sid (dla gościa)
function getCartSessionId(): string
{
  if (empty($_COOKIE['cart_sid']) || !preg_match('/^[a-f0-9]{32}$/', (string)$_COOKIE['cart_sid'])) {
    $sid = bin2hex(random_bytes(16));
    setcookie('cart_sid', $sid, time() + 60 * 60 * 24 * 30, '/', '', false, true);
    $_COOKIE['cart_sid'] = $sid;
  }
  return $_COOKIE['cart_sid'];
}

// Helper: zbuduj mini-koszyk (jak w cart/add.php)
function buildMiniCart(PDO $pdo, int $ownerId, array $opts, string $cdnBase, float $freeShipThreshold): array
{
  $items = [];
  $total = 0.0;

  if (!empty($opts['order_group_id'])) {
    $st = $pdo->prepare("
      SELECT oi.product_id, oi.quantity, oi.price, p.name, COALESCE(pi.image_path,'') AS image_path
      FROM order_items oi
      JOIN products p ON p.id=oi.product_id
      LEFT JOIN product_images pi ON pi.product_id=p.id AND pi.is_main=1
      WHERE oi.order_group_id=:gid
      ORDER BY oi.id DESC
      LIMIT 20
    ");
    $st->execute([':gid' => (int)$opts['order_group_id']]);
  } else {
    $st = $pdo->prepare("
      SELECT ci.product_id, ci.quantity, ci.unit_price AS price, p.name, COALESCE(pi.image_path,'') AS image_path
      FROM cart_items ci
      JOIN products p ON p.id=ci.product_id
      LEFT JOIN product_images pi ON pi.product_id=p.id AND pi.is_main=1
      WHERE ci.owner_id=:oid AND ci.session_id=:sid
      ORDER BY ci.updated_at DESC, ci.id DESC
      LIMIT 20
    ");
    $st->execute([':oid' => $ownerId, ':sid' => (string)$opts['session_id']]);
  }

  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $lineTotal = ((int)$row['quantity']) * (float)$row['price'];
    $total    += $lineTotal;

    $thumb = $row['image_path']
      ? $cdnBase . '/uploads/products/' . ltrim($row['image_path'], '/')
      : '/images/placeholder.png';

    $items[] = [
      'id'         => (int)$row['product_id'],
      'name'       => (string)$row['name'],
      'qty'        => (int)$row['quantity'],
      'price'      => number_format((float)$row['price'], 2, ',', ' ') . ' PLN',
      'line_total' => number_format($lineTotal, 2, ',', ' ') . ' PLN',
      'thumb'      => $thumb
    ];
  }

  $missing = max(0.0, $freeShipThreshold - $total);
  $freeShipping = [
    'progress_pct'      => $freeShipThreshold > 0 ? min(100, ($total / $freeShipThreshold * 100)) : 0,
    'missing'           => $missing,
    'missing_formatted' => $missing > 0 ? number_format($missing, 2, ',', ' ') . ' PLN' : null
  ];

  return ['items' => $items, 'free_shipping' => $freeShipping];
}

// ---------- [3] ZALOGOWANY? Usuwamy z order_items aktualnej grupy 'nowe' ----------
if (!empty($_SESSION['client_id']) && !empty($_SESSION['client_token'])) {
  $clientId    = (int)$_SESSION['client_id'];

  // Znajdź ostatnią grupę z zamówienia w statusie 'nowe'
  $q = $pdo->prepare("
    SELECT og.id AS order_group_id
    FROM orders o
    JOIN order_groups og ON og.order_id = o.id
    WHERE o.client_id = :cid AND o.owner_id = :oid AND o.order_status = 'nowe'
    ORDER BY og.created_at DESC, og.id DESC
    LIMIT 1
  ");
  $q->execute([':cid' => $clientId, ':oid' => $ownerId]);
  $pgzId = (int)($q->fetchColumn() ?: 0);

  if ($pgzId > 0) {
    // Usuń z order_items
    $del = $pdo->prepare("DELETE FROM order_items WHERE order_group_id=:gid AND product_id=:pid LIMIT 1");
    $del->execute([':gid' => $pgzId, ':pid' => $productId]);

    // (Porządek) Usunięcie ewentualnej pozycji z cart_items (jakby kiedyś się zdublowało)
    if (!empty($_SESSION['client_token'])) {
      $tok = (string)$_SESSION['client_token'];
      $pdo->prepare("DELETE FROM cart_items WHERE owner_id=:oid AND client_token=:tok AND product_id=:pid")
        ->execute([':oid' => $ownerId, ':tok' => $tok, ':pid' => $productId]);
    }

    // Mini + free shipping
    $mini = buildMiniCart($pdo, $ownerId, ['order_group_id' => $pgzId], $cdnBase, $freeShipThreshold);

    echo json_encode([
      'status'        => 'ok',
      'message'       => 'Usunięto z koszyka',
      'mini'          => $mini,
      'free_shipping' => $mini['free_shipping']
    ]);
    exit;
  }

  // Brak aktywnej grupy 'nowe' → spadamy do trybu gościa (bez tworzenia nowego zamówienia)
}

// ---------- [4] GOŚĆ: usuwamy z cart_items po session_id ----------
$sessionId = getCartSessionId();

$pdo->prepare("
  DELETE FROM cart_items
  WHERE owner_id=:oid AND session_id=:sid AND product_id=:pid
  LIMIT 1
")->execute([
  ':oid' => $ownerId,
  ':sid' => $sessionId,
  ':pid' => $productId
]);

// Mini + free shipping
$mini = buildMiniCart($pdo, $ownerId, ['session_id' => $sessionId], $cdnBase, $freeShipThreshold);

echo json_encode([
  'status'        => 'ok',
  'message'       => 'Usunięto z koszyka',
  'mini'          => $mini,
  'free_shipping' => $mini['free_shipping']
]);
