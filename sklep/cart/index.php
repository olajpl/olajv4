<?php
// cart/index.php — widok koszyka (V4) z autodetekcją kolumn i spójnym CSRF
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

// === DEV: włącz widoczne błędy TYLKO na czas testów (potem OFF) ===
ini_set('display_errors', '1');
ini_set('html_errors', '1');
error_reporting(E_ALL);

try {
  $client = autoLoginFromCookie(); // ewentualne auto-logowanie z ciasteczka
  $isLoggedIn   = !empty($_SESSION['client_id']) && !empty($_SESSION['client_token']);
  $clientId     = (int)($_SESSION['client_id'] ?? 0);
  $clientToken  = (string)($_SESSION['client_token'] ?? '');
  $owner_id     = (int)($client['owner_id'] ?? 1);
  $settings     = getShopSettings($owner_id);

  $themeColor   = $settings['theme_color'] ?? '#ec4899';
  $currency     = $settings['currency'] ?? 'PLN';
  $cdn          = rtrim($settings['cdn_url'] ?? 'https://panel.olaj.pl', '/');

  // === detekcja kolumn w cart_items/products ===
  function tableHas(PDO $pdo, string $table, string $col): bool
  {
    static $cache = [];
    $key = $table;
    if (!isset($cache[$key])) {
      $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
      $st->execute([':t' => $table]);
      $arr = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
      $cache[$key] = array_flip(array_map('strtolower', $arr));
    }
    return isset($cache[$key][strtolower($col)]);
  }

  $ci_has_client_token = tableHas($pdo, 'cart_items', 'client_token');
  $ci_has_cart_sid     = tableHas($pdo, 'cart_items', 'cart_sid');
  $ci_has_session_id   = tableHas($pdo, 'cart_items', 'session_id'); // legacy
  $ci_has_qty          = tableHas($pdo, 'cart_items', 'qty');
  $ci_has_quantity     = tableHas($pdo, 'cart_items', 'quantity');
  $ci_has_unit_price   = tableHas($pdo, 'cart_items', 'unit_price');
  $ci_has_price        = tableHas($pdo, 'cart_items', 'price');

  $p_has_unit_price    = tableHas($pdo, 'products', 'unit_price');
  $p_has_price         = tableHas($pdo, 'products', 'price');

  // kolumny efektywne
  $col_qty     = $ci_has_qty ? 'qty' : ($ci_has_quantity ? 'quantity' : 'qty');
  $col_price   = $ci_has_unit_price ? 'unit_price' : ($ci_has_price ? 'price' : 'unit_price');
  $p_col_price = $p_has_unit_price ? 'unit_price' : ($p_has_price ? 'price' : 'unit_price');

  // klucz sesji dla koszyka gościa
  $cartSid = getCartSid(); // helper z includes/helpers.php

  // WHERE zależnie od schematu
  $where_col_logged = $ci_has_client_token ? 'client_token' : ($ci_has_cart_sid ? 'cart_sid' : 'session_id');
  $where_val_logged = $ci_has_client_token ? $clientToken   : ($ci_has_cart_sid ? $cartSid    : $cartSid);

  $where_col_guest  = $ci_has_cart_sid ? 'cart_sid' : 'session_id';
  $where_val_guest  = $cartSid;

  // ===== SUMA QTY =====
  if ($isLoggedIn) {
    $qSum = $pdo->prepare("
      SELECT COALESCE(SUM($col_qty),0) AS qty_sum
      FROM cart_items
      WHERE owner_id = :oid AND $where_col_logged = :w AND $col_qty > 0
    ");
    $qSum->execute([':oid' => $owner_id, ':w' => $where_val_logged]);
  } else {
    $qSum = $pdo->prepare("
      SELECT COALESCE(SUM($col_qty),0) AS qty_sum
      FROM cart_items
      WHERE owner_id = :oid AND $where_col_guest = :w AND $col_qty > 0
    ");
    $qSum->execute([':oid' => $owner_id, ':w' => $where_val_guest]);
  }
  $cartQtySum = (int)$qSum->fetchColumn();

  // ===== POZYCJE KOSZYKA =====
  if ($isLoggedIn) {
    $stmt = $pdo->prepare("
      SELECT c.product_id AS id, p.name, c.$col_qty AS qty,
             COALESCE(c.$col_price, p.$p_col_price, 0) AS price,
             COALESCE(pi.image_path,'') AS image_path
      FROM cart_items c
      LEFT JOIN products p ON p.id = c.product_id
      LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_main = 1
      WHERE c.owner_id = :oid AND c.$where_col_logged = :w AND c.$col_qty > 0
      ORDER BY c.id DESC
    ");
    $stmt->execute([':oid' => $owner_id, ':w' => $where_val_logged]);
  } else {
    $stmt = $pdo->prepare("
      SELECT c.product_id AS id, p.name, c.$col_qty AS qty,
             COALESCE(c.$col_price, p.$p_col_price, 0) AS price,
             COALESCE(pi.image_path,'') AS image_path
      FROM cart_items c
      LEFT JOIN products p ON p.id = c.product_id
      LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_main = 1
      WHERE c.owner_id = :oid AND c.$where_col_guest = :w AND c.$col_qty > 0
      ORDER BY c.id DESC
    ");
    $stmt->execute([':oid' => $owner_id, ':w' => $where_val_guest]);
  }
  $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // suma
  $total = 0.0;
  foreach ($items as $it) {
    $total += (float)$it['price'] * (float)$it['qty'];
  }

  // === CSRF do submitu — taki sam jak w submit.php (cookie csrf_cart)
  if (empty($_SESSION['csrf_cart']) && empty($_COOKIE['csrf_cart'])) {
    $t = bin2hex(random_bytes(16));
    $_SESSION['csrf_cart'] = $t;
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    setcookie('csrf_cart', $t, [
      'expires'  => time() + 60 * 60 * 24 * 7,
      'path'     => '/',
      'secure'   => $isHttps,
      'httponly' => false,
      'samesite' => 'Lax',
    ]);
  }
  $csrf = $_COOKIE['csrf_cart'] ?? $_SESSION['csrf_cart'] ?? '';
} catch (Throwable $e) {
  // awaryjny fallback widoczny na stronie (zamiast białej)
  http_response_code(500);
  echo "<!doctype html><meta charset='utf-8'><pre style='padding:16px'>❌ Błąd: "
    . htmlspecialchars($e->getMessage())
    . "\n\nPlik: " . htmlspecialchars($e->getFile()) . ":" . (int)$e->getLine()
    . "</pre>";
  exit;
}

// === widok HTML ===
function imgUrl(string $cdn, ?string $rel): string
{
  return $rel ? ($cdn . '/uploads/products/' . ltrim($rel, '/')) : 'https://via.placeholder.com/64?text=%20';
}
?>
<!doctype html>
<html lang="pl">

<head>
  <meta charset="utf-8">
  <title>Koszyk — Olaj.pl</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">
  <div class="max-w-3xl mx-auto p-4">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-xl font-bold">🧺 Twój koszyk</h1>
      <a href="/" class="text-sm text-blue-600 hover:underline">Kontynuuj zakupy</a>
    </div>

    <?php if ($cartQtySum <= 0): ?>
      <div class="bg-white border rounded-xl p-8 text-center text-gray-600">
        <div class="text-3xl mb-2">⚠️</div>
        <div>Koszyk jest pusty.</div>

        <?php if (!$isLoggedIn): ?>
          <div class="mt-6 grid gap-2 max-w-sm mx-auto">
            <a href="/konto/recover.php?redirect=%2Fcheckout%2Findex.php"
              class="block w-full text-center py-3 rounded-lg text-white font-semibold"
              style="background-color: <?= htmlspecialchars($themeColor) ?>;">🔑 Odzyskaj dostęp</a>
            <a href="/konto/register.php?redirect=%2Fcheckout%2Findex.php"
              class="block w-full text-center py-3 rounded-lg font-semibold border">✍️ Zarejestruj</a>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="bg-white border rounded-xl p-4 divide-y">
        <?php foreach ($items as $it): ?>
          <?php $line = (float)$it['price'] * (float)$it['qty']; ?>
          <div class="py-3 flex items-center gap-3 group" id="item-<?= (int)$it['id'] ?>">
            <img src="<?= imgUrl($cdn, $it['image_path'] ?? '') ?>" class="w-14 h-14 rounded object-cover" alt="">
            <div class="flex-1">
              <div class="font-medium"><?= htmlspecialchars($it['name'] ?? 'Produkt') ?></div>
              <button
                onclick="removeFromCart(<?= (int)$it['id'] ?>)"
                class="text-sm text-red-600 hover:underline hidden group-hover:inline-block ml-2"
                title="Usuń produkt z koszyka">🗑 Usuń</button>
              <div class="text-xs text-gray-500">
                <?= number_format((float)$it['price'], 2, ',', ' ') ?> <?= htmlspecialchars($currency) ?> × <?= (float)$it['qty'] ?>
              </div>
            </div>
            <div class="font-semibold">
              <?= number_format($line, 2, ',', ' ') ?> <?= htmlspecialchars($currency) ?>
            </div>
          </div>
        <?php endforeach; ?>
        <div class="py-3 flex items-center justify-between">
          <div class="text-gray-500">Suma</div>
          <div class="text-lg font-bold"><?= number_format($total, 2, ',', ' ') ?> <?= htmlspecialchars($currency) ?></div>
        </div>
      </div>

      <div class="mt-4 grid gap-2 sm:grid-cols-2">
        <?php if ($isLoggedIn): ?>
          <form method="post" action="/cart/submit.php" class="sm:col-span-2">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit"
              class="w-full text-center py-3 rounded-lg text-white font-semibold"
              style="background-color: <?= htmlspecialchars($themeColor) ?>;">Przejdź do checkout</button>
          </form>
        <?php else: ?>
          <a href="/konto/recover.php?redirect=%2Fcheckout%2Findex.php"
            class="text-center py-3 rounded-lg text-white font-semibold"
            style="background-color: <?= htmlspecialchars($themeColor) ?>;">🔑 Odzyskaj dostęp</a>
          <a href="/konto/register.php?redirect=%2Fcheckout%2Findex.php"
            class="text-center py-3 rounded-lg font-semibold border">✍️ Zarejestruj</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <footer class="text-center text-xs text-gray-400 mt-8">© <?= date('Y') ?> Olaj.pl</footer>
  </div>
  <script src="/assets/js/shop.js" defer></script>
</body>

</html>