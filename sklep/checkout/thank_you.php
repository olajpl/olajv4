<?php
// shop/checkout/thank_you.php — widok podziękowania z per-PGZ kosztami dostawy + sumą dla całego zamówienia
declare(strict_types=1);

// ✅ Loader checkout (po checkout_token)
require_once __DIR__ . '/../includes/checkout_loader.php';   // $pdo, $checkout, $checkout_token
// Wspólne helpery i logi
require_once __DIR__ . '/../includes/helpers.php';         // fmt_weight(), itd.
require_once __DIR__ . '/../includes/log.php';             // wlog()/logg()
require_once __DIR__ . '/../includes/shipping.php';

/* --- mini-helpers --- */
if (!function_exists('e')) {
  function e(string $s): string
  {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
}
if (!function_exists('fmt_price')) {
  function fmt_price(float $v): string
  {
    return number_format($v, 2, ',', ' ') . ' zł';
  }
}
if (!function_exists('fmt_account')) {
  function fmt_account(?string $acc): string
  {
    $acc = preg_replace('/\s+/', '', (string)$acc);
    return trim(chunk_split((string)$acc, 4, ' '));
  }
}

/* --- pomocnicze: bezpieczne sprawdzanie istnienia tabeli/kolumny --- */
if (!function_exists('tableExists')) {
  function tableExists(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare("SHOW TABLES LIKE :t");
      $st->execute([':t' => $table]);
      return (bool)$st->fetchColumn();
    } catch (\Throwable $__) { return false; }
  }
}
if (!function_exists('columnExists')) {
  function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
      $st->execute([':c' => $column]);
      return (bool)$st->fetchColumn();
    } catch (\Throwable $__) { return false; }
  }
}

/* --- WAGOWE helpery (guardy) --- */
/**
 * W Twojej bazie nie ma tabeli `settings`, więc:
 * - jeżeli tabela istnieje → użyj ustawienia globalnego
 * - jeżeli nie istnieje → zwróć null (brak globalnego limitu)
 */
if (!function_exists('getGlobalMaxPackageWeight')) {
  function getGlobalMaxPackageWeight(PDO $pdo, int $ownerId): ?float
  {
    if (!tableExists($pdo, 'settings')) {
      return null; // brak tabeli settings → brak globalnego limitu
    }
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE owner_id = :oid AND `key` = 'max_package_weight' LIMIT 1");
    $stmt->execute(['oid' => $ownerId]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (float)$v : null;
  }
}

if (!function_exists('getGroupTotalWeight')) {
  function getGroupTotalWeight(PDO $pdo, int $orderGroupId): float
  {
    // baza ma products.weight_kg oraz order_items.qty
    $q = $pdo->prepare("
      SELECT COALESCE(SUM(COALESCE(p.weight_kg,0) * COALESCE(oi.qty,0)),0) AS total_weight
      FROM order_items oi
      LEFT JOIN products p ON p.id = oi.product_id
      WHERE oi.order_group_id = :gid
    ");
    $q->execute(['gid' => $orderGroupId]);
    return (float)($q->fetchColumn() ?: 0.0);
  }
}

/**
 * Zwraca szczegółowy koszt dla JEDNEJ paczki (PGZ):
 * ['cost'=>float, 'packages'=>int, 'limit'=>float|null, 'weight'=>float]
 */
if (!function_exists('calcShippingCostDetailed')) {
  function calcShippingCostDetailed(PDO $pdo, int $ownerId, int $orderGroupId, int $shippingMethodId): array
  {
    $weight = getGroupTotalWeight($pdo, $orderGroupId);

    $sm = $pdo->prepare("SELECT default_price, max_package_weight FROM shipping_methods WHERE id = :id AND owner_id = :oid AND active = 1 LIMIT 1");
    $sm->execute(['id' => $shippingMethodId, 'oid' => $ownerId]);
    $method = $sm->fetch(PDO::FETCH_ASSOC);
    if (!$method) return ['cost' => 0.0, 'packages' => 0, 'limit' => null, 'weight' => $weight];

    $defaultPrice = (float)($method['default_price'] ?? 0.0);
    $methodMax    = isset($method['max_package_weight']) ? (float)$method['max_package_weight'] : null;

    $globalMax = getGlobalMaxPackageWeight($pdo, $ownerId); // może być null (i to OK)
    $limit = $methodMax ?: ($globalMax ?: null);

    $packages = ($limit && $limit > 0) ? (int)ceil($weight / $limit) : ($weight > 0 ? 1 : 0);

    $remaining = $weight;
    $sum = 0.0;

    for ($i = 0; $i < $packages; $i++) {
      $w = ($limit && $limit > 0) ? min($limit, $remaining) : $remaining;
      $remaining = max(0.0, $remaining - $w);

      // Reguła wagowa dla paczki "o wadze $w"
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
      $st->execute(['mid' => $shippingMethodId, 'w1' => $w, 'w2' => $w]);
      $rulePrice = $st->fetchColumn();
      $sum += ($rulePrice !== false) ? (float)$rulePrice : $defaultPrice;
    }

    return ['cost' => $sum, 'packages' => $packages, 'limit' => $limit, 'weight' => $weight];
  }
}

/**
 * Suma kosztów dostawy dla CAŁEGO zamówienia (sumujemy per-PGZ)
 * Zwraca: ['cost'=>float, 'packages'=>int, 'groups'=>int, 'weights_sum'=>float]
 */
if (!function_exists('calcOrderShippingCost')) {
  function calcOrderShippingCost(PDO $pdo, int $ownerId, int $orderId, int $shippingMethodId): array
  {
    $gq = $pdo->prepare("SELECT id FROM order_groups WHERE order_id = :oid ORDER BY created_at ASC, id ASC");
    $gq->execute(['oid' => $orderId]);
    $groupIds = $gq->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $totalCost = 0.0;
    $totalPackages = 0;
    $weightsSum = 0.0;

    foreach ($groupIds as $gid) {
      $det = calcShippingCostDetailed($pdo, $ownerId, (int)$gid, $shippingMethodId);
      $totalCost    += $det['cost'];
      $totalPackages += $det['packages'];
      $weightsSum   += $det['weight'];
    }

    return ['cost' => $totalCost, 'packages' => $totalPackages, 'groups' => count($groupIds), 'weights_sum' => $weightsSum];
  }
}

/* ============ split notice (flash z sesji) ============ */
$splitFlash = null;
if (!empty($_SESSION['split_notice']) && is_array($_SESSION['split_notice'])) {
  foreach (array_reverse($_SESSION['split_notice']) as $sn) {
    if (!empty($sn['ts']) && (time() - (int)$sn['ts']) > 86400) continue;
    if (!empty($sn['for_order_id']) && (int)$sn['for_order_id'] === (int)$orderId) {
      $splitFlash = $sn;
      break;
    }
  }
}

/* --- kontekst z loadera --- */
$orderId  = (int)($checkout['order_id'] ?? 0);
$groupId  = (int)($checkout['order_group_id'] ?? 0);
$ownerId  = (int)($checkout['owner_id'] ?? 0);
$clientId = (int)($checkout['client_id'] ?? 0);
$token    = (string)($checkout['token'] ?? '');
$status   = (string)($checkout['order_status'] ?? '');

/* CSRF */
if (empty($_SESSION['csrf_checkout'])) $_SESSION['csrf_checkout'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_checkout'];

/* --- metoda płatności przypięta do TEJ paczki --- */
$st = $pdo->prepare("
  SELECT pm.*
  FROM payments p
  LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
  WHERE p.order_group_id = :gid
  ORDER BY p.id DESC
  LIMIT 1
");
$st->execute([':gid' => $groupId]);
$payMethod = $st->fetch(PDO::FETCH_ASSOC) ?: null;

/* --- paid_status paczki --- */
$st = $pdo->prepare("SELECT COALESCE(paid_status, 'nieopłacona') FROM order_groups WHERE id = :gid LIMIT 1");
$st->execute([':gid' => $groupId]);
$groupPaidStatus = (string)($st->fetchColumn() ?: 'nieopłacona');

/* --- produkty paczki + sumy --- */
$st = $pdo->prepare("
  SELECT 
    oi.id, oi.product_id,
    oi.qty AS quantity,
    COALESCE(oi.unit_price, p.unit_price, 0) AS unit_price,
    COALESCE(NULLIF(oi.name,''), p.name) AS product_name,
    COALESCE( oi.source_type, 'shop') AS src,
    COALESCE(p.weight_kg,0) AS weight
  FROM order_items oi
  LEFT JOIN products p ON p.id = oi.product_id
  WHERE oi.order_group_id = :gid
");
$st->execute([':gid' => $groupId]);
$items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$total_products = 0.0;
foreach ($items as $it) $total_products += ((float)$it['unit_price']) * (float)$it['quantity'];

/* --- WAGA tej paczki --- */
$groupWeight = getGroupTotalWeight($pdo, $groupId);

/* --- wybrana metoda na zamówieniu (shipping_id w orders) --- */
$q = $pdo->prepare("SELECT shipping_id FROM orders WHERE id = :oid LIMIT 1");
$q->execute(['oid' => $orderId]);
$current_shipping_id = (int)($q->fetchColumn() ?: 0);

// ===== Konsolidowana dostawa na poziomie całego zamówienia =====
$orderShipping = ['total_cost' => 0.0, 'parcel_count' => 0, 'limit_kg' => 0.0, 'total_kg' => 0.0];
if ($current_shipping_id > 0) {
  // dostarczane przez includes/shipping.php
  $orderShipping = calcConsolidatedOrderShipping($pdo, $ownerId, $orderId, $current_shipping_id);
}

/* --- koszt dostawy TEJ paczki + sumaryczny dla CAŁEGO zamówienia --- */
$shipping_cost_group   = 0.0;          // ta paczka
$order_shipping_total  = 0.0;          // suma wszystkich paczek
$order_packages_total  = 0;            // suma liczby paczek (parceli)
$group_packages        = 0;            // paczek w tej PGZ
$limitLabel            = '';           // label limitu dla tej PGZ

if ($current_shipping_id > 0) {
  $det = calcShippingCostDetailed($pdo, $ownerId, $groupId, $current_shipping_id);
  $shipping_cost_group = $det['cost'];
  $group_packages      = $det['packages'];
  $limitLabel          = ($det['limit'] && $det['limit'] > 0) ? ('limit ' . fmt_weight((float)$det['limit'])) : 'bez limitu';

  $ord = calcOrderShippingCost($pdo, $ownerId, $orderId, $current_shipping_id);
  $order_shipping_total = $ord['cost'];
  $order_packages_total = $ord['packages'];

  wlog("thank_you.shipping: owner={$ownerId}, order={$orderId}, group={$groupId}, method={$current_shipping_id}, group_w={$det['weight']}, group_cost={$shipping_cost_group}, order_cost={$order_shipping_total}, group_pkg={$group_packages}, order_pkg={$order_packages_total}");
} else {
  // Brak przypiętej metody – pokaż najtańsze heurystycznie
  $stm = $pdo->prepare("SELECT id FROM shipping_methods WHERE owner_id = :oid AND active = 1 ORDER BY name ASC, id ASC");
  $stm->execute(['oid' => $ownerId]);
  $ids = $stm->fetchAll(PDO::FETCH_COLUMN) ?: [];
  $bestGroup = null;
  $bestOrder = null;

  foreach ($ids as $mid) {
    $dg = calcShippingCostDetailed($pdo, $ownerId, $groupId, (int)$mid)['cost'];
    $do = calcOrderShippingCost($pdo, $ownerId, $orderId, (int)$mid)['cost'];
    $bestGroup = is_null($bestGroup) ? $dg : min($bestGroup, $dg);
    $bestOrder = is_null($bestOrder) ? $do : min($bestOrder, $do);
  }
  $shipping_cost_group  = (float)($bestGroup ?? 0.0);
  $order_shipping_total = (float)($bestOrder ?? 0.0);
}

/* --- SUMA tej paczki (produkty + jej dostawa) --- */
$total_sum_group = $total_products + $shipping_cost_group;

/* --- metody dostawy (do dropdowna) --- */
$stm = $pdo->prepare("
  SELECT id, name, default_price, type, max_package_weight
  FROM shipping_methods
  WHERE owner_id = :oid AND active = 1
  ORDER BY name ASC, id ASC
");
$stm->execute([':oid' => $ownerId]);
$methods = $stm->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* --- adres (obecna wersja) --- */
$st = $pdo->prepare("
  SELECT *
  FROM shipping_addresses
  WHERE client_id = :clientId AND owner_id = :ownerId
  ORDER BY is_default DESC, created_at DESC
  LIMIT 1
");
$st->execute([
  'clientId' => $clientId,
  'ownerId'  => $ownerId
]);
$address = $st->fetch(PDO::FETCH_ASSOC);


if (!$address && $clientId > 0) {
  $st = $pdo->prepare("
    SELECT sa.* FROM shipping_addresses sa
    JOIN order_groups og ON og.id = sa.order_group_id
    JOIN orders o ON o.id = og.order_id
    WHERE o.client_id = :cid
    ORDER BY sa.updated_at DESC, sa.id DESC
    LIMIT 1
  ");
  $st->execute([':cid' => $clientId]);
  $address = $st->fetch(PDO::FETCH_ASSOC) ?: [
    'full_name' => '',
    'phone' => '',
    'email' => '',
    'street' => '',
    'postcode' => '',
    'city' => '',
    'locker_code' => '',
    'locker_desc' => '',
    'note' => ''
  ];
}

/* --- lista wszystkich PGZ (do tabeli) + suma produktów per PGZ --- */
/* UWAGA: w Twojej bazie NIE ma kolumny og.checkout_token. Używamy og.group_token do linku (?token=grp-...) */
$groupsStmt = $pdo->prepare("
  SELECT 
    og.id,
    og.group_token,           -- <== zamiast og.checkout_token
    og.created_at,
    og.group_number,
    og.paid_status,
    COUNT(oi.id) AS product_count,
    SUM(COALESCE(oi.unit_price, p.unit_price, 0) * COALESCE(oi.qty,0)) AS sum_total
  FROM order_groups og
  JOIN orders o ON o.id = og.order_id
  LEFT JOIN order_items oi ON oi.order_group_id = og.id
  LEFT JOIN products p ON p.id = oi.product_id
  WHERE o.id = :oid
  GROUP BY og.id
  ORDER BY og.created_at DESC, og.id DESC
");
$groupsStmt->execute([':oid' => $orderId]);
$all_groups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* --- flagi UI --- */
$terminal_statuses = ['gotowe_do_wysyłki', 'wysłane', 'zrealizowane', 'zarchiwizowane', 'anulowane'];
$can_edit_delivery = !in_array($status, $terminal_statuses, true);

/* --- badge helpers (UI) --- */
function badgeSource(string $src): string
{
  $map = [
    'shop' => ['🛒', 'bg-emerald-100 text-emerald-800 border-emerald-200'],
    'live' => ['🎥', 'bg-fuchsia-100 text-fuchsia-800 border-fuchsia-200'],
    'facebook_comment' => ['💬', 'bg-blue-100 text-blue-800 border-blue-200'],
    'messenger' => ['✉️', 'bg-indigo-100 text-indigo-800 border-indigo-200'],
    'chat' => ['💬', 'bg-slate-100 text-slate-800 border-slate-200'],
    'mobile' => ['📱', 'bg-amber-100 text-amber-800 border-amber-200'],
    'manual' => ['✍️', 'bg-gray-100 text-gray-800 border-gray-200'],
    'video' => ['📺', 'bg-purple-100 text-purple-800 border-purple-200'],
  ];
  [$icon, $cls] = $map[$src] ?? ['🟩', 'bg-emerald-100 text-emerald-800 border-emerald-200'];
  return "<span class=\"px-2 py-0.5 text-[11px] rounded-full border $cls\">$icon " . e($src) . "</span>";
}
function badgePaidStatus(string $ps): string
{
  $map = [
    'nieopłacona' => ['⏳', 'bg-orange-100 text-orange-800 border-orange-200'],
    'częściowa'  => ['🟡', 'bg-yellow-100 text-yellow-800 border-yellow-200'],
    'opłacona'   => ['✅', 'bg-emerald-100 text-emerald-800 border-emerald-200'],
    'nadpłata'   => ['💚', 'bg-teal-100 text-teal-800 border-teal-200'],
  ];
  [$icon, $cls] = $map[$ps] ?? ['ℹ️', 'bg-slate-100 text-slate-800 border-slate-200'];
  return "<span class=\"px-2 py-0.5 text-[11px] rounded-full border $cls\">$icon " . e($ps) . "</span>";
}

/* Nazwa metody (UI) */
$shippingName = '';
if ($current_shipping_id > 0) {
  $s = $pdo->prepare("SELECT name FROM shipping_methods WHERE id = :sid LIMIT 1");
  $s->execute([':sid' => $current_shipping_id]);
  $shippingName = (string)($s->fetchColumn() ?: '');
}
?>
<!DOCTYPE html>
<html lang="pl">

<head>
  <meta charset="UTF-8" />
  <title>Dziękujemy za zamówienie</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .glass { backdrop-filter: blur(8px); background: rgba(255, 255, 255, .78); }
    .brand-grad { background: linear-gradient(135deg, #f43f5e 0%, #ec4899 45%, #8b5cf6 100%); }
    .brand-text { background: linear-gradient(135deg, #f43f5e 0%, #ec4899 60%, #8b5cf6 100%); -webkit-background-clip: text; background-clip: text; color: transparent; }
    .shine { position: relative; overflow: hidden; }
    .shine:after { content: ""; position: absolute; top: -50%; left: -30%; width: 60%; height: 200%; transform: rotate(25deg);
      background: linear-gradient(90deg, transparent, rgba(255,255,255,.35), transparent); animation: shine 2.6s infinite; }
    @keyframes shine { 0% { left: -30% } 100% { left: 130% } }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-br from-pink-50 via-rose-50 to-violet-50">
  <header class="brand-grad text-white shadow-lg">
    <div class="max-w-5xl mx-auto px-3 sm:px-4 py-4 sm:py-5 flex items-center justify-between">
      <h1 class="text-xl sm:text-3xl font-extrabold tracking-tight shine">✅ Dziękujemy za zamówienie!</h1>
      <a href="/" class="hidden sm:inline-block px-4 py-2 rounded-lg bg-white/10 hover:bg-white/20">Wróć do sklepu</a>
    </div>
  </header>

  <main class="max-w-5xl mx-auto px-3 sm:px-4 py-5 sm:py-8 grid lg:grid-cols-3 gap-4 sm:gap-5">
    <?php if ($splitFlash): ?>
      <div class="max-w-5xl mx-auto mb-3 sm:mb-4">
        <div class="px-3 sm:px-4 py-3 rounded-xl border bg-red-50 border-red-200 text-red-800">
          <div class="font-bold mb-1">⚠️ Twoja paczka przekroczyła limit wagowy.</div>
          <div class="text-sm">
            Zamówienie zostało <strong>podzielone na dwie paczki</strong>.
            <a class="underline font-semibold" href="<?= e((string)$splitFlash['summary_link']) ?>">Przejdź do nowego podsumowania</a>
            <span class="text-[11px] opacity-80 ml-1">(<a class="underline" href="<?= e((string)$splitFlash['checkout_link']) ?>">albo do checkoutu</a>)</span>.
          </div>
        </div>
      </div>
    <?php endif; ?>

    <section class="lg:col-span-2 space-y-4 sm:space-y-5">
      <!-- PACZKA -->
      <div class="glass rounded-2xl shadow-xl border border-white/60">
        <div class="p-4 sm:p-6 border-b">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-slate-500">Zamówienie #<?= (int)$orderId ?> • Paczka #<?= (int)$groupId ?></div>
              <div class="font-black text-lg sm:text-xl brand-text">Twoja paczka</div>
            </div>
            <div class="text-sm"><?= badgePaidStatus($groupPaidStatus) ?></div>
          </div>
        </div>

        <div class="p-4 sm:p-6 divide-y">
          <?php if (empty($items)): ?>
            <div class="text-slate-500 py-8 text-center">Brak pozycji w tej paczce.</div>
            <?php else: foreach ($items as $it):
              $qty = (float)$it['quantity'];
              $u = (float)$it['unit_price'];
              $line = $qty * $u;
              $src = (string)$it['src'];
              $wkg = (float)$it['weight'];
              $wline = $wkg * $qty;
            ?>
              <div class="py-3 flex items-start gap-3">
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 flex-wrap">
                    <div class="font-semibold truncate"><?= e($it['product_name'] ?? 'Produkt') ?></div>
                    <div class="flex items-center gap-1 flex-wrap">
                      <?= badgeSource($src) ?> <?= badgePaidStatus($groupPaidStatus) ?>
                    </div>
                  </div>
                  <div class="text-sm text-slate-500">
                    Ilość: <?= $qty ?> × <?= fmt_price($u) ?>
                    <?php if ($wkg > 0): ?> • Waga: <?= fmt_weight($wkg) ?> × <?= $qty ?> = <strong><?= fmt_weight($wline) ?></strong><?php endif; ?>
                  </div>
                </div>
                <div class="font-bold"><?= fmt_price($line) ?></div>
              </div>
          <?php endforeach; endif; ?>
        </div>

        <div class="p-4 sm:p-6 bg-white/60 rounded-b-2xl">
          <div class="grid grid-cols-2 gap-3">
            <div class="glass rounded-xl p-3 sm:p-4 border border-white/70">
              <div class="text-slate-500 text-xs sm:text-sm">Produkty</div>
              <div class="text-lg sm:text-xl font-extrabold"><?= fmt_price($total_products) ?></div>
            </div>
            <div class="glass rounded-xl p-3 sm:p-4 border border-white/70">
              <div class="text-slate-500 text-xs sm:text-sm">Dostawa (ta paczka)</div>
              <div class="text-lg sm:text-xl font-extrabold"><?= fmt_price($shipping_cost_group) ?></div>
              <?php if ($current_shipping_id > 0): ?>
                <div class="text-[11px] text-slate-500 mt-1">
                  Waga: <strong><?= fmt_weight($groupWeight) ?></strong>
                  • Paczek w tej PGZ: <strong><?= (int)$group_packages ?></strong>
                  <?= $limitLabel ? '(' . htmlspecialchars($limitLabel) . ')' : '' ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="text-sm sm:text-base mb-2">
              <div>Dostawa łącznie (zamówienie): <strong><?= fmt_price((float)$orderShipping['total_cost']) ?></strong></div>
              <div class="text-xs text-slate-600">
                Waga zamówienia: <?= number_format((float)$orderShipping['total_kg'], 2, ',', ' ') ?> kg
                • Limit informacyjny: <?= number_format((float)$orderShipping['limit_kg'], 2, ',', ' ') ?> kg
                <?php if (!empty($orderShipping['rules_suspended'])): ?>
                  • <span class="text-rose-600 font-semibold">reguły wagowe chwilowo wyłączone</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="glass rounded-xl p-3 sm:p-4 border border-white/70">
              <div class="text-slate-500 text-xs sm:text-sm">Suma tej paczki</div>
              <div class="text-base sm:text-lg font-black"><?= fmt_price($total_sum_group) ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- LISTA PGZ -->
      <div class="glass rounded-2xl shadow-xl border border-white/60">
        <button type="button" onclick="toggleAcc('acc-pgz')" class="w-full text-left p-4 sm:p-6 flex items-center justify-between">
          <div>
            <div class="text-xs sm:text-sm text-slate-500">Zamówienie #<?= (int)$orderId ?></div>
            <div class="font-black text-lg">📦 Wszystkie paczki (PGZ)</div>
          </div>
          <span id="acc-pgz-icon" class="text-slate-500">▼</span>
        </button>
        <div id="acc-pgz" class="hidden px-4 sm:px-6 pb-4 sm:pb-6">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-slate-500">
                <tr>
                  <th class="text-left py-2">Paczka</th>
                  <th class="text-left py-2">Status opłaty</th>
                  <th class="text-right py-2">Pozycji</th>
                  <th class="text-right py-2">Suma</th>
                  <th class="text-right py-2">Akcja</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <?php foreach ($all_groups as $g):
                  $label = !empty($g['group_number']) ? ('#' . $g['group_number']) : ('PGZ ' . $g['id']);
                  $isCurrent = ((int)$g['id'] === $groupId);
                  $cnt = (int)($g['product_count'] ?? 0);
                  $sum = (float)($g['sum_total'] ?? 0.0);
                  $ps  = (string)($g['paid_status'] ?? 'nieopłacona');
                ?>
                  <tr>
                    <td class="py-2">
                      <div class="font-semibold"><?= e($label) ?></div>
                      <div class="text-[11px] text-slate-500"><?= e(date('Y-m-d H:i', strtotime($g['created_at']))) ?></div>
                    </td>
                    <td class="py-2"><?= badgePaidStatus($ps) ?></td>
                    <td class="py-2 text-right"><?= $cnt ?></td>
                    <td class="py-2 text-right font-semibold"><?= fmt_price($sum) ?></td>
                    <td class="py-2 text-right">
                      <?php if ($isCurrent): ?>
                        <span class="text-[11px] text-emerald-700">[ta paczka]</span>
                      <?php else: ?>
                        <!-- linkuj po group_token → loader obsługuje ?token=grp-* -->
                        <a class="px-3 py-1 rounded-lg border hover:bg-white/70" href="?token=<?= urlencode((string)$g['group_token']) ?>">Pokaż</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($all_groups)): ?>
                  <tr>
                    <td colspan="5" class="py-4 text-center text-slate-500">Brak innych paczek.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ZMIANA DOSTAWY / ADRESU (po checkboxie) -->
      <?php if ($can_edit_delivery): ?>
        <div class="glass rounded-2xl shadow-xl border border-white/60">
          <div class="p-4 sm:p-6 border-b">
            <div class="font-black text-lg">✏️ Zmień metodę dostawy / adres</div>
            <?php if (!empty($_SESSION['delivery_error'])): ?>
              <div class="mt-3 p-3 rounded-lg bg-red-100 text-red-800 border border-red-200 text-sm">
                <?= e((string)$_SESSION['delivery_error']) ?>
              </div>
              <?php unset($_SESSION['delivery_error']); ?>
            <?php endif; ?>

            <!-- ✅ przełącznik widoczności formularza -->
            <label class="mt-3 inline-flex items-center gap-2 text-sm">
              <input type="checkbox" id="toggleDeliveryForm" class="h-4 w-4">
              <span>Pokaż formularz zmiany dostawy/adresu</span>
            </label>
          </div>

          <!-- ✅ wrapper ukrywający formularz + disable pól, gdy schowany -->
          <div id="deliveryFormWrap" class="p-4 sm:p-6 hidden">
            <form method="post" class="grid gap-3" id="deliveryForm">
              <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">
              <input type="hidden" name="action" value="update_delivery">

              <label class="block">
                <span class="text-sm text-slate-600">Metoda dostawy</span>
                <select name="shipping_method_id" class="w-full mt-1 border rounded-lg p-2 bg-white" required>
                  <option value="">— wybierz —</option>
                  <?php foreach ($methods as $m):
                    $gPrev = calcShippingCostDetailed($pdo, $ownerId, $groupId, (int)$m['id'])['cost'];
                    $oPrev = calcOrderShippingCost($pdo, $ownerId, $orderId, (int)$m['id'])['cost'];
                  ?>
                    <option value="<?= (int)$m['id'] ?>" <?= ((int)$m['id'] === (int)$current_shipping_id) ? 'selected' : '' ?>>
                      <?= e($m['name']) ?> (ta paczka: <?= fmt_price($gPrev) ?> • całość: <?= fmt_price($oPrev) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if ($shippingName): ?>
                  <div class="mt-1 text-xs text-slate-500">Aktualnie: <strong>Metoda: <?= e($shippingName) ?></strong></div>
                <?php endif; ?>
                <div class="mt-1 text-[11px] text-slate-500">
                  Waga tej paczki: <?= fmt_weight($groupWeight) ?>
                </div>
              </label>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block"><span class="text-sm text-slate-600">Imię i nazwisko</span>
                  <input name="full_name" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['full_name'] ?? '')) ?>" required>
                </label>
                <label class="block"><span class="text-sm text-slate-600">Telefon</span>
                  <input name="phone" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['phone'] ?? '')) ?>" required>
                </label>
              </div>

              <label class="block"><span class="text-sm text-slate-600">E-mail</span>
                <input name="email" type="email" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['email'] ?? '')) ?>">
              </label>

              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <label class="block sm:col-span-2"><span class="text-sm text-slate-600">Ulica</span>
                  <input name="street" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['street'] ?? '')) ?>">
                </label>
                <label class="block"><span class="text-sm text-slate-600">Kod</span>
                  <input name="postcode" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['postcode'] ?? '')) ?>">
                </label>
              </div>

              <label class="block"><span class="text-sm text-slate-600">Miasto</span>
                <input name="city" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['city'] ?? '')) ?>">
              </label>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block"><span class="text-sm text-slate-600">Kod paczkomatu (opcjonalnie)</span>
                  <input name="locker_code" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['locker_code'] ?? '')) ?>">
                </label>
                <label class="block"><span class="text-sm text-slate-600">Opis paczkomatu (opcjonalnie)</span>
                  <input name="locker_desc" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['locker_desc'] ?? '')) ?>">
                </label>
              </div>

              <label class="block"><span class="text-sm text-slate-600">Uwagi</span>
                <textarea name="note" rows="2" class="w-full border rounded-lg p-2 mt-1"><?= e((string)($address['note'] ?? '')) ?></textarea>
              </label>

              <div class="pt-2">
                <button class="px-4 py-2 rounded-xl bg-pink-600 hover:bg-pink-700 text-white font-semibold shadow">Zapisz zmiany</button>
              </div>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <!-- ZAMKNIĘCIE PACZKI -->
      <div class="glass rounded-2xl shadow-xl border border-white/60">
        <div class="p-4 sm:p-6 border-b">
          <div class="font-black text-lg">📦 Zamknij paczkę</div>
          <div class="text-sm text-slate-600 mt-1">Po zamknięciu paczki nie będzie można jej ponownie otworzyć ani dodawać produktów.</div>
        </div>
        <div class="p-4 sm:p-6">
          <form method="post" action="/checkout/send_package.php" id="closePkgForm" class="grid gap-3">
            <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <label class="flex items-start gap-2">
              <input id="closeConfirm" type="checkbox" name="confirm_lock" value="1" class="mt-1">
              <span class="text-sm">Rozumiem, że <strong>zamknięta paczka</strong> nie może zostać ponownie otwarta i <strong>nie można</strong> do niej dołączać nowych produktów.</span>
            </label>
            <button id="closeBtn" type="submit" disabled
              class="px-4 py-2 rounded-xl bg-slate-300 text-white font-semibold shadow disabled:opacity-50 disabled:cursor-not-allowed">
              Zamknij paczkę
            </button>
            <div class="text-xs text-slate-500">Wyśle żądanie do <code>/checkout/send_package.php</code> (status „do wysyłki”).</div>
          </form>
        </div>
      </div>
    </section>

    <!-- PRAWA kolumna -->
    <aside class="lg:col-span-1 space-y-4 sm:space-y-5">
      <!-- PŁATNOŚĆ -->
      <div class="glass rounded-2xl shadow-xl border border-white/60">
        <div class="p-4 sm:p-6 border-b">
          <div class="font-black text-lg">💳 Płatność</div>
        </div>
        <div class="p-4 sm:p-6 space-y-3">
          <?php if ($payMethod): ?>
            <div class="text-sm text-slate-700 font-semibold">
              Metoda: <span class="brand-text font-bold"><?= e((string)$payMethod['name']) ?></span>
            </div>

            <?php if (($payMethod['type'] ?? '') === 'przelew'): ?>
              <?php if (!empty($payMethod['bank_account_name'])): ?>
                <div class="flex items-center justify-between text-sm">
                  <span><strong>Odbiorca:</strong> <?= e($payMethod['bank_account_name']) ?></span>
                  <button type="button" class="copy-btn text-indigo-600 underline" data-copy="<?= e($payMethod['bank_account_name']) ?>">Kopiuj</button>
                </div>
              <?php endif; ?>
              <?php if (!empty($payMethod['bank_account_number'])): ?>
                <div class="flex items-center justify-between text-sm">
                  <span><strong>Konto:</strong> <?= e(fmt_account($payMethod['bank_account_number'])) ?></span>
                  <button type="button" class="copy-btn text-indigo-600 underline" data-copy="<?= e($payMethod['bank_account_number']) ?>">Kopiuj</button>
                </div>
              <?php endif; ?>
              <?php $transferTitle = "nr zamówienia: {$orderId} - pgz: {$groupId}"; ?>
              <div class="flex items-center justify-between text-sm">
                <span><strong>Tytuł:</strong> <?= e($transferTitle) ?></span>
                <button type="button" class="copy-btn text-indigo-600 underline" data-copy="<?= e($transferTitle) ?>">Kopiuj</button>
              </div>
              <?php if (!empty($payMethod['bank_description'])): ?>
                <div class="text-sm text-slate-600"><?= nl2br(e($payMethod['bank_description'])) ?></div>
              <?php endif; ?>

            <?php elseif (($payMethod['type'] ?? '') === 'blik'): ?>
              <div class="rounded-xl p-4 brand-grad text-white">
                <div class="text-sm opacity-90">Płatność BLIK</div>
                <div class="text-2xl sm:text-3xl font-extrabold tracking-wide mt-1">
                  <?php if (!empty($payMethod['blik_phone'])): ?>
                    <a class="underline decoration-white/40 hover:decoration-white" href="tel:<?= e($payMethod['blik_phone']) ?>"><?= e($payMethod['blik_phone']) ?></a>
                  <?php else: ?>— brak numeru telefonu BLIK —<?php endif; ?>
                </div>
                <?php if (!empty($payMethod['bank_description'])): ?>
                  <div class="text-xs sm:text-sm mt-2 opacity-95"><?= nl2br(e($payMethod['bank_description'])) ?></div>
                <?php endif; ?>
                <?php if (!empty($payMethod['blik_phone'])): ?>
                  <button type="button" class="mt-3 px-3 py-2 rounded-lg bg-white/10 hover:bg-white/20 copy-btn" data-copy="<?= e($payMethod['blik_phone']) ?>">Skopiuj numer</button>
                <?php endif; ?>
              </div>

            <?php elseif (($payMethod['type'] ?? '') === 'pobranie'): ?>
              <div class="text-sm text-slate-600">Płatność przy odbiorze (pobranie).</div>

            <?php elseif (($payMethod['type'] ?? '') === 'gotówka'): ?>
              <div class="text-sm text-slate-600">Płatność gotówką przy odbiorze.</div>

            <?php elseif (($payMethod['type'] ?? '') === 'online'): ?>
              <div class="text-sm text-slate-600">Płatność online — znajdziesz ją w zakładce płatności zamówienia (jeśli została zainicjowana).</div>

            <?php else: ?>
              <div class="text-sm text-slate-600">Szczegóły płatności pojawią się w trakcie finalizacji lub nie są wymagane dla tej metody.</div>
            <?php endif; ?>
          <?php else: ?>
            <div class="text-sm text-slate-600">Brak przypiętej metody płatności do tej paczki.</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- SKRÓT (ta paczka + łączna dostawa) -->
      <div class="glass rounded-2xl shadow-xl border border-white/60 p-4 sm:p-6">
        <div class="text-slate-500 text-sm">Podsumowanie</div>
        <div class="mt-2 text-3xl font-black brand-text"><?= fmt_price($total_sum_group) ?></div>
        <div class="mt-1 text-xs text-slate-500">
          Produkty: <?= fmt_price($total_products) ?> • Dostawa (ta paczka): <?= fmt_price($shipping_cost_group) ?> • Waga: <?= fmt_weight($groupWeight) ?>
        </div>
        <div class="text-sm sm:text-base mb-2">
          <div>Dostawa łącznie (zamówienie): <strong><?= fmt_price((float)$orderShipping['total_cost']) ?></strong></div>
          <div class="text-xs text-slate-600">
            Łączna liczba paczek (parceli): <strong><?= (int)$orderShipping['parcel_count'] ?></strong>
            • Waga zamówienia: <?= number_format((float)$orderShipping['total_kg'], 2, ',', ' ') ?> kg
            • Limit paczki: <?= number_format((float)$orderShipping['limit_kg'], 2, ',', ' ') ?> kg
          </div>
        </div>

        <a href="/" class="inline-block w-full text-center px-4 py-2 rounded-xl border hover:bg-white/70">Wróć do sklepu</a>
      </div>
    </aside>
  </main>
  <?php
  // skonsumuj wpisy split_notice związane z tym orderem (żeby baner nie wracał)
  if ($splitFlash) {
    $_SESSION['split_notice'] = array_values(array_filter($_SESSION['split_notice'], function ($sn) use ($orderId) {
      return (int)($sn['for_order_id'] ?? 0) !== (int)$orderId;
    }));
  }
  ?>

  <script>
    function toggleAcc(id) {
      const el = document.getElementById(id);
      const ic = document.getElementById(id + '-icon');
      if (!el) return;
      el.classList.toggle('hidden');
      if (ic) ic.textContent = el.classList.contains('hidden') ? '▼' : '▲';
    }
    document.addEventListener('click', (e) => {
      const b = e.target.closest('.copy-btn');
      if (!b) return;
      const v = b.getAttribute('data-copy') || '';
      navigator.clipboard.writeText(v).then(() => {
        const old = b.textContent;
        b.textContent = 'Skopiowano!';
        setTimeout(() => b.textContent = old, 1200);
      });
    });
    const chk = document.getElementById('closeConfirm');
    const btn = document.getElementById('closeBtn');
    if (chk && btn) chk.addEventListener('change', () => btn.disabled = !chk.checked);

    // ✅ Sterowanie formularzem zmiany dostawy/adresu
    (function() {
      const toggle = document.getElementById('toggleDeliveryForm');
      const wrap = document.getElementById('deliveryFormWrap');
      const form = document.getElementById('deliveryForm');
      if (!toggle || !wrap || !form) return;

      function setDisabled(disabled) {
        const fields = form.querySelectorAll('input, select, textarea, button');
        fields.forEach(el => {
          if (el.name === 'csrf' || el.name === 'action') return;
          el.disabled = !!disabled;
        });
      }

      function applyState(show) {
        wrap.classList.toggle('hidden', !show);
        setDisabled(!show);
        try { localStorage.setItem('olaj_thankyou_delivery_form', show ? '1' : '0'); } catch (e) {}
      }
      let saved = '0';
      try { saved = localStorage.getItem('olaj_thankyou_delivery_form') || '0'; } catch (e) {}
      const initial = saved === '1';
      toggle.checked = initial;
      applyState(initial);
      toggle.addEventListener('change', () => applyState(toggle.checked));
    })();
  </script>
</body>
</html>
