<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/** 🔌 Loader: daje $pdo, $checkout, automatyczny redirect gdy checkout_completed */
require_once __DIR__ . '/../includes/checkout_loader.php';
require_once __DIR__ . '/../includes/log.php';

/** 🎯 Kontekst z loadera */
$orderId  = (int)($checkout['order_id'] ?? 0);
$ownerId  = (int)($checkout['owner_id'] ?? 0);
$clientId = (int)($checkout['client_id'] ?? 0);
$groupId  = (int)($checkout['order_group_id'] ?? 0);
$token    = (string)($checkout['token'] ?? '');
$status   = (string)($checkout['order_status'] ?? '');

/** 👮 Pas bezpieczeństwa: jeśli brak tokenu/kontekstu, 404 */
if ($orderId <= 0 || $token === '') {
    http_response_code(404);
    exit('Brak kontekstu checkout.');
}

/** 🧪 CSRF do akcji na tej stronie (jeśli potrzebujesz) */
if (empty($_SESSION['csrf_checkout'])) {
    $_SESSION['csrf_checkout'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_checkout'];

// === Helpery wagowe (jak w index.php) ===
function getGlobalMaxPackageWeight(PDO $pdo, int $ownerId): ?float
{
  $stmt = $pdo->prepare("SELECT value FROM settings WHERE owner_id = :oid AND `key` = 'max_package_weight' LIMIT 1");
  $stmt->execute(['oid' => $ownerId]);
  $v = $stmt->fetchColumn();
  return $v !== false ? (float)$v : null;
}
function getGroupTotalWeight(PDO $pdo, int $orderGroupId): float
{
  $q = $pdo->prepare("
        SELECT COALESCE(SUM(COALESCE(p.weight,0) * COALESCE(oi.quantity,0)),0) AS total_weight
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_group_id = :gid
    ");
  $q->execute(['gid' => $orderGroupId]);
  return (float)($q->fetchColumn() ?: 0.0);
}
function resolveWeightRulePrice(PDO $pdo, int $shippingMethodId, float $onePackageWeight): ?float
{
  $st = $pdo->prepare("
        SELECT price
        FROM shipping_weight_rules
        WHERE shipping_method_id = :mid
          AND (min_weight IS NULL OR min_weight <= :w1)
          AND (max_weight IS NULL OR max_weight >= :w2)
        ORDER BY 
          (CASE WHEN min_weight IS NULL THEN 0 ELSE 1 END) DESC,
          min_weight DESC
        LIMIT 1
    ");
  $st->execute([
    'mid' => $shippingMethodId,
    'w1'  => $onePackageWeight,
    'w2'  => $onePackageWeight
  ]);
  $p = $st->fetchColumn();
  return $p === false ? null : (float)$p;
}
function calcShippingCost(PDO $pdo, int $ownerId, int $orderGroupId, int $shippingMethodId): float
{
  $totalWeight = getGroupTotalWeight($pdo, $orderGroupId);

  $sm = $pdo->prepare("SELECT default_price, max_package_weight FROM shipping_methods WHERE id = :id AND owner_id = :oid AND active = 1 LIMIT 1");
  $sm->execute(['id' => $shippingMethodId, 'oid' => $ownerId]);
  $method = $sm->fetch(PDO::FETCH_ASSOC);
  if (!$method) return 0.0;

  $defaultPrice = (float)($method['default_price'] ?? 0.0);
  $methodMax    = isset($method['max_package_weight']) ? (float)$method['max_package_weight'] : null;

  $globalMax = getGlobalMaxPackageWeight($pdo, $ownerId);
  $limit = $methodMax ?: ($globalMax ?: null);

  if ($limit !== null && $limit > 0) {
    $packages = (int)ceil($totalWeight / $limit);
  } else {
    $packages = 1;
    $limit = $totalWeight;
  }

  $remaining = $totalWeight;
  $sum = 0.0;

  for ($i = 0; $i < $packages; $i++) {
    $w = min($limit, $remaining);
    $remaining = max(0.0, $remaining - $w);

    $rulePrice = resolveWeightRulePrice($pdo, $shippingMethodId, $w);
    $sum += ($rulePrice !== null) ? $rulePrice : $defaultPrice;
  }

  wlog("shipping.calc summary: owner={$ownerId}, group={$orderGroupId}, method={$shippingMethodId}, weight={$totalWeight}, price={$sum}");
  return $sum;
}

// === Formatery ===
function formatPrice($amount): string
{
  return number_format((float)$amount, 2, ',', ' ') . ' zł';
}
function fmt_weight(float $kg): string
{
  if ($kg > 0 && $kg < 1) return number_format($kg * 1000, 0, ',', ' ') . ' g';
  return number_format($kg, 2, ',', ' ') . ' kg';
}

// === Prefill adresu wysyłki (jak było) ===
// mamy: $pdo, $orderId, $clientId z checkout_loader.php
// 1) Adres zapisany dla TEGO zamówienia (prawda nadrzędna)
$st = $pdo->prepare("
  SELECT 
    full_name, phone, email,
    street, postcode, city,
    locker_code, locker_desc, note
  FROM shipping_addresses
  WHERE order_id = :oid
  LIMIT 1
");
$st->execute([':oid' => $orderId]);
$address = $st->fetch(PDO::FETCH_ASSOC) ?: null;

// 2) Fallback: domyślny / najnowszy adres klienta
if (!$address) {
  $ca = $pdo->prepare("
    SELECT 
      full_name, phone, email,
      street, postcode, city,
      locker_code, locker_desc
    FROM client_addresses
    WHERE client_id = :cid
    ORDER BY is_default DESC, updated_at DESC, id DESC
    LIMIT 1
  ");
  $ca->execute([':cid' => $clientId]);
  $def = $ca->fetch(PDO::FETCH_ASSOC) ?: null;

  if ($def) {
    $address = [
      'full_name'   => (string)$def['full_name'],
      'phone'       => (string)$def['phone'],
      'email'       => (string)($def['email'] ?? ''),
      'street'      => (string)$def['street'],
      'postcode'    => (string)$def['postcode'],
      'city'        => (string)$def['city'],
      'locker_code' => (string)($def['locker_code'] ?? ''),
      'locker_desc' => (string)($def['locker_desc'] ?? ''),
      'note'        => '',
    ];
  }
}

// 3) Fallback do clients (name → full_name), gdy nie ma nic powyżej
if (!$address) {
  $cl = $pdo->prepare("SELECT name AS full_name, phone, email FROM clients WHERE id = :cid LIMIT 1");
  $cl->execute([':cid' => $clientId]);
  $c = $cl->fetch(PDO::FETCH_ASSOC) ?: [];
  $address = [
    'full_name'   => (string)($c['full_name'] ?? ''),
    'phone'       => (string)($c['phone'] ?? ''),
    'email'       => (string)($c['email'] ?? ''),
    'street'      => '',
    'postcode'    => '',
    'city'        => '',
    'locker_code' => '',
    'locker_desc' => '',
    'note'        => '',
  ];
}

// (opcjonalnie) log diagnostyczny
if (empty($address['street']) && function_exists('logg')) {
  logg('info','checkout.summary','address.fallback', [
    'order_id'=>$orderId,'client_id'=>$clientId, 'level'=> empty($def) ? 'clients' : 'client_addresses'
  ]);
}


// === Produkty paczki ===
$stmt = $pdo->prepare("
  SELECT
    oi.id,
    oi.product_id,
    COALESCE(oi.quantity, 0)                 AS qty,
    COALESCE(oi.unit_price, oi.price, 0)     AS unit_price,
    COALESCE(NULLIF(oi.custom_name,''), p.name) AS product_name,
    p.vat_rate,
    COALESCE(p.weight,0) AS weight
  FROM order_items oi
  LEFT JOIN products p ON p.id = oi.product_id
  WHERE oi.order_group_id = :group_id
");
$stmt->execute(['group_id' => (int)$checkout['order_group_id']]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Suma produktów
$total_products = array_reduce(
  $items,
  fn(float $sum, array $i) => $sum + ((float)$i['unit_price'] * (int)$i['qty']),
  0.0
);

$groupWeight = getGroupTotalWeight($pdo, (int)$checkout['order_group_id']);

// === Metody płatności (aktywne) ===
$stmt = $pdo->prepare("
    SELECT *
    FROM payment_methods
    WHERE owner_id = :owner_id AND active = 1
    ORDER BY position ASC, id ASC
");
$stmt->execute(['owner_id' => (int)$checkout['owner_id']]);
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// === Paczki tego zamówienia (do akordeonu) ===
$sqlGroups = "
  SELECT
      og.id,
      og.checkout_token,
      og.created_at,
      og.group_number,
      COUNT(oi.id)                                      AS product_count,
      COALESCE(MAX(oi.source), 'shop')                  AS source
  FROM order_groups og
  JOIN orders o   ON o.id = og.order_id
  LEFT JOIN order_items oi ON oi.order_group_id = og.id
  WHERE o.id = :order_id
  GROUP BY og.id
  ORDER BY og.created_at DESC, og.id DESC
";
$st = $pdo->prepare($sqlGroups);
$st->execute(['order_id' => (int)$checkout['order_id']]);
$all_groups = $st->fetchAll(PDO::FETCH_ASSOC);

// Pierwsza paczka (tylko ona płaci dostawę)
$stmt = $pdo->prepare("
    SELECT og.id
    FROM order_groups og
    WHERE og.order_id = :oid
    ORDER BY og.created_at ASC, og.id ASC
    LIMIT 1
");
$stmt->execute(['oid' => (int)$checkout['order_id']]);
$first_group_id = (int)($stmt->fetchColumn() ?: 0);
$is_first_group = ((int)$checkout['order_group_id'] === $first_group_id);

// aktualnie wybrana metoda w orders
$q = $pdo->prepare("SELECT shipping_id FROM orders WHERE id = :oid LIMIT 1");
$q->execute(['oid' => (int)$checkout['order_id']]);
$currentShippingId = (int)($q->fetchColumn() ?: 0);

// Koszt dostawy tylko dla pierwszej paczki
$shipping_cost = 0.0;
$packages = 1;
$limitLabel = '';
if ($is_first_group && $currentShippingId > 0) {
  $shipping_cost = calcShippingCost($pdo, (int)$checkout['owner_id'], (int)$checkout['order_group_id'], $currentShippingId);

  // policz też liczbę paczek i limit (do UI)
  $globalMax = getGlobalMaxPackageWeight($pdo, (int)$checkout['owner_id']);
  $sm = $pdo->prepare("SELECT max_package_weight FROM shipping_methods WHERE id = :id LIMIT 1");
  $sm->execute(['id' => $currentShippingId]);
  $methodMax = $sm->fetchColumn();
  $limit = ($methodMax && (float)$methodMax > 0) ? (float)$methodMax : ($globalMax ?? 0.0);
  $packages = ($limit > 0) ? (int)ceil($groupWeight / $limit) : 1;
  $limitLabel = ($limit > 0) ? "limit " . fmt_weight($limit) : "bez limitu";
}

// SUMA KOŃCOWA
$total_sum = $total_products + $shipping_cost;

// === POST: wybór płatności + zmiana statusu ===
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $method_id = isset($_POST['payment_method_id']) ? (int)$_POST['payment_method_id'] : 0;

  if ($method_id > 0) {
    $upd = $pdo->prepare("
            UPDATE order_groups
            SET payment_method_id = :pmid
            WHERE id = :gid
        ");
    $upd->execute([
      'pmid' => $method_id,
      'gid'  => (int)$checkout['order_group_id']
    ]);
  }

  if ((string)$checkout['order_status'] === 'nowe') {
    $pdo->prepare("
            UPDATE orders
            SET order_status = 'otwarta_paczka:add_products', updated_at = NOW()
            WHERE id = :oid
        ")->execute(['oid' => (int)$checkout['order_id']]);
  }

  header("Location: complete.php?token=" . urlencode((string)$checkout['token']));
  exit;
}
?>
<!DOCTYPE html>
<html lang="pl">

<head>
  <meta charset="UTF-8">
  <title>Podsumowanie zamówienia</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen py-8 px-4">

  <div class="max-w-xl mx-auto bg-white p-6 rounded-xl shadow">
    <h1 class="text-2xl font-semibold mb-4">🧾 Podsumowanie zamówienia</h1>

    <!-- Lista produktów -->
    <ul class="divide-y divide-gray-200 mb-6">
      <?php foreach ($items as $item): ?>
        <li class="py-2 flex justify-between">
          <span><?= htmlspecialchars((string)$item['product_name']) ?> × <?= (int)$item['qty'] ?></span>
          <span><?= formatPrice(((float)$item['unit_price']) * (int)$item['qty']) ?></span>
        </li>
      <?php endforeach; ?>
      <?php if (empty($items)): ?>
        <li class="py-2 text-gray-500">Brak pozycji w paczce.</li>
      <?php endif; ?>
    </ul>

    <!-- Podsumowanie kwot + waga -->
    <div class="text-sm sm:text-base mb-6">
      <div>Produkty: <strong><?= formatPrice($total_products) ?></strong></div>
      <div>Waga produktów: <strong><?= fmt_weight($groupWeight) ?></strong></div>
      <?php if ($shipping_cost > 0): ?>
        <div>Dostawa: <strong><?= formatPrice($shipping_cost) ?></strong></div>
        <div class="text-xs text-gray-500">Paczki: <?= (int)$packages ?> <?= $limitLabel ? '(' . htmlspecialchars($limitLabel) . ')' : '' ?></div>
      <?php endif; ?>
      <div class="mt-1 font-bold">Suma: <?= formatPrice($total_sum) ?></div>
    </div>

    <!-- Formularz wyboru płatności -->
    <form method="POST" class="space-y-4">
      <h2 class="font-semibold text-lg">💳 Wybierz metodę płatności</h2>
      <?php foreach ($payment_methods as $method): ?>
        <label class="block border p-4 rounded-lg cursor-pointer hover:border-blue-500">
          <input type="radio" name="payment_method_id" value="<?= (int)$method['id'] ?>" required class="mr-2">
          <?= htmlspecialchars((string)$method['name']) ?>
        </label>
      <?php endforeach; ?>
      <?php if (empty($payment_methods)): ?>
        <p class="text-sm text-gray-500">Brak aktywnych metod płatności.</p>
      <?php endif; ?>
      <button type="submit" class="w-full mt-4 bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700">
        Złóż zamówienie
      </button>
    </form>
  </div>

  <?php if (!empty($all_groups)): ?>
    <div class="max-w-xl mx-auto mt-6 bg-white p-6 rounded-xl shadow">
      <button type="button"
        class="w-full flex justify-between items-center text-left text-xl font-bold focus:outline-none"
        onclick="document.getElementById('accordion-other-groups').classList.toggle('hidden')">
        📦 Twoje paczki (Facebook + Sklep)
        <span class="text-gray-500 text-sm">(kliknij, aby rozwinąć)</span>
      </button>
      <div id="accordion-other-groups" class="mt-4 hidden">
        <ul class="divide-y divide-gray-200">
          <?php foreach ($all_groups as $g): ?>
            <?php
            $is_current = ((int)$g['id'] === (int)$checkout['order_group_id']);
            $label = !empty($g['group_number'])
              ? ('Paczka #' . (int)$g['group_number'])
              : ('Paczka z ' . date('d.m.Y', strtotime((string)$g['created_at'])));
            $count = (int)($g['product_count'] ?? 0);
            ?>
            <li class="py-2 flex items-center justify-between gap-3">
              <div class="flex items-center gap-2">
                <span class="text-sm"><?= htmlspecialchars($label) ?></span>
                <span class="text-xs px-2 py-0.5 rounded bg-gray-100"><?= $count ?> prod.</span>
                <span class="text-xs">🟩 Sklep</span>
              </div>
              <?php if ($is_current): ?>
                <span class="text-xs text-emerald-700">[ta paczka]</span>
              <?php else: ?>
                <a href="summary.php?token=<?= urlencode((string)$g['checkout_token']) ?>" class="text-blue-600 hover:underline text-sm">Pokaż</a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

</body>

</html>