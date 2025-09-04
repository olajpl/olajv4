<?php
// checkout/index.php – Etap 1: wybór metody dostawy (mobile-first, V4) – konsolidacja 23kg
declare(strict_types=1);

// 🔌 Loader: ustawia $pdo, $checkout, $checkout_token i startuje sesję
require_once __DIR__ . '/../includes/checkout_loader.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/shipping.php';
require_once __DIR__ . '/../includes/log.php'; // olaj_v4_logger

// ─────────────────────────────────────────────────────────────────────────────
// [0] Kontekst z loadera (używamy TYLKO tego, co dał loader)
// ─────────────────────────────────────────────────────────────────────────────
$ownerId = (int)($checkout['owner_id'] ?? 0);
$orderId = (int)($checkout['order_id'] ?? 0);
$groupId = (int)($checkout['order_group_id'] ?? 0);
$token   = (string)($checkout['token'] ?? '');
$status  = (string)($checkout['order_status'] ?? '');

// Guard na zamknięty checkout (loader też to robi, ale mamy podwójny pas bezpieczeństwa)
$done    = !empty($checkout['checkout_completed']);
$force   = (isset($_GET['change_delivery']) && $_GET['change_delivery'] === '1');

$can_change_in_status = in_array($status, ['nowe', 'otwarta_paczka:add_products', 'otwarta_paczka:payment_only'], true);
$legacyClosed = ['wyslane', 'wysłane', 'zrealizowane', 'anulowane', 'zarchiwizowane', 'gotowe_do_wysyłki', 'w_realizacji'];
$paczkaStates = ['otwarta_paczka:add_products', 'otwarta_paczka:payment_only', 'do_wyslania'];

if ($done && !$force) {
    header('Location: thank_you.php?token=' . urlencode($token));
    exit;
}
if (!$force && (in_array($status, $paczkaStates, true) || in_array($status, $legacyClosed, true))) {
    header('Location: thank_you.php?token=' . urlencode($token));
    exit;
}
if ($force && !$can_change_in_status) {
    header('Location: thank_you.php?token=' . urlencode($token));
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
/** (opcjonalnie) cookie klienta – przydatne dla powrotu do checkoutu */
function setClientTokenCookie(string $token): void {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    setcookie('client_token', $token, [
        'expires'  => time() + 3600*24*365,
        'path'     => '/',
        'domain'   => '.olaj.pl',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (function_exists('wlog')) wlog('auth.client_token_cookie_set');
}
// ─────────────────────────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────────────────────────
// [1] Aktualna metoda dostawy z orders
// ─────────────────────────────────────────────────────────────────────────────
$currentShippingId = 0;
$q = $pdo->prepare('SELECT shipping_id FROM orders WHERE id = :oid LIMIT 1');
$q->execute(['oid' => $orderId]);
$currentShippingId = (int)($q->fetchColumn() ?: 0);

// ─────────────────────────────────────────────────────────────────────────────
// [2] Metody dostawy dostępne dla ownera
// ─────────────────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
  SELECT id, name, default_price, active, max_package_weight
  FROM shipping_methods
  WHERE owner_id = :owner_id AND active = 1
  ORDER BY name ASC, id ASC
");
$stmt->execute(['owner_id' => $ownerId]);
$methods = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ─────────────────────────────────────────────────────────────────────────────
// [3] Ustawienia sklepu / waluta
// ─────────────────────────────────────────────────────────────────────────────
$settings = getShopSettings($ownerId);
$currency = $settings['currency'] ?? 'PLN';

// ─────────────────────────────────────────────────────────────────────────────
// [4] CSRF do tego kroku
// ─────────────────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_checkout'])) {
    $_SESSION['csrf_checkout'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_checkout'];

// ─────────────────────────────────────────────────────────────────────────────
// [5] POST: zapis wyboru metody do orders + redirect do danych adresowych
// ─────────────────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($CSRF, $csrf)) {
        http_response_code(403);
        exit('Niepoprawny token bezpieczeństwa.');
    }

    $selected_method = (int)($_POST['shipping_method_id'] ?? 0);
    if ($selected_method <= 0) {
        http_response_code(422);
        exit('Nie wybrano metody dostawy.');
    }

    $chk = $pdo->prepare("
        SELECT id, name, default_price
        FROM shipping_methods
        WHERE id = :id AND owner_id = :owner_id AND active = 1
        LIMIT 1
    ");
    $chk->execute(['id' => $selected_method, 'owner_id' => $ownerId]);
    $method = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$method) {
        http_response_code(422);
        exit('Nieprawidłowa metoda dostawy.');
    }

    // zapis do orders
    $upd = $pdo->prepare("UPDATE orders SET shipping_id = :sid, updated_at = NOW() WHERE id = :oid LIMIT 1");
    $upd->execute([':sid' => (int)$method['id'], ':oid' => $orderId]);

    // cache w sesji (opcjonalnie)
    $_SESSION['checkout']['shipping_method_id'] = (int)$method['id'];

    $suffix = $force ? '&change_delivery=1' : '';
    header('Location: details.php?token=' . urlencode($token) . $suffix);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// [6] Mini-koszyk (pozycje w TEJ grupie) + sumy produktów
// ─────────────────────────────────────────────────────────────────────────────
$oi = $pdo->prepare("
  SELECT 
    oi.product_id, oi.quantity, oi.unit_price,
    COALESCE(NULLIF(oi.custom_name,''), p.name) AS product_name,
    COALESCE(p.weight,0) AS weight
  FROM order_items oi
  LEFT JOIN products p ON p.id = oi.product_id
  WHERE oi.order_group_id = :gid
");
$oi->execute(['gid' => $groupId]);
$cartItems = $oi->fetchAll(PDO::FETCH_ASSOC) ?: [];

$subtotal = 0.0;
foreach ($cartItems as $ci) {
    $unit = (isset($ci['unit_price']) && (float)$ci['unit_price'] > 0) ? (float)$ci['unit_price'] : 0.0;
    $qty  = (int)$ci['quantity'];
    $subtotal += $unit * $qty;
}

// ─────────────────────────────────────────────────────────────────────────────
// [7] Skonsolidowana waga/koszt dla CAŁEGO zamówienia (po aktualnej metodzie)
// ─────────────────────────────────────────────────────────────────────────────
$orderTotalWeight   = getOrderTotalWeight($pdo, $orderId);
$operationalLimitKg = getOperationalParcelLimitKg($pdo, $ownerId);

$currentShippingCost = 0.0;
$orderParcels        = 0;

if ($currentShippingId > 0) {
    $con = calcOrderShippingConsolidated($pdo, $ownerId, $orderId, $currentShippingId);
    $currentShippingCost = (float)($con['total_cost']   ?? 0.0);
    $orderTotalWeight    = (float)($con['total_weight'] ?? $orderTotalWeight);
    $orderParcels        = (int)  ($con['parcels']      ?? 0);
}

$total = $subtotal + $currentShippingCost;

// ─────────────────────────────────────────────────────────────────────────────
// [8] Helpery UI
// ─────────────────────────────────────────────────────────────────────────────
function shippingIcon(string $name): string {
    $n = mb_strtolower($name);
    if (strpos($n, 'inpost') !== false)   return '📦';
    if (strpos($n, 'kurier') !== false)   return '🚚';
    if (strpos($n, 'osobisty') !== false) return '🏬';
    if (strpos($n, 'rower') !== false)    return '🚲';
    return '🛵';
}
function pln($v, $currency = 'PLN') {
    $v = (float)$v;
    return number_format($v, 2, ',', ' ') . ' ' . $currency;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Wybierz metodę dostawy</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen py-4 px-3">
    <div class="max-w-xl mx-auto bg-white p-4 rounded-xl shadow">
        <!-- Mini koszyk -->
        <?php if (!empty($cartItems)): ?>
            <div class="mb-4 border-b pb-3">
                <h2 class="text-lg font-semibold mb-2">Twoje produkty</h2>
                <ul class="space-y-1 text-sm">
                    <?php foreach ($cartItems as $ci): ?>
                        <?php
                        $unit_price = (isset($ci['unit_price']) && (float)$ci['unit_price'] > 0) ? (float)$ci['unit_price'] : 0.0;
                        $qty        = (int)$ci['quantity'];
                        $line_total = $unit_price * $qty;
                        $pw         = (float)$ci['weight'];
                        $lw         = $pw * $qty;
                        ?>
                        <li class="flex justify-between items-start">
                            <div class="flex flex-col">
                                <span><?= htmlspecialchars((string)$ci['product_name']) ?> × <?= $qty ?></span>
                                <?php if ($pw > 0): ?>
                                    <span class="text-xs text-gray-500">Waga: <?= number_format($pw, 2, ',', ' ') ?> kg × <?= $qty ?> = <strong><?= number_format($lw, 2, ',', ' ') ?> kg</strong></span>
                                <?php endif; ?>
                            </div>
                            <span><?= pln($line_total, $currency) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="mt-3 pt-3 border-t text-sm">
                    <div class="flex justify-between"><span>Produkty</span><strong><?= pln($subtotal, $currency) ?></strong></div>

                    <?php if ($currentShippingId > 0): ?>
                        <div class="flex justify-between"><span>Dostawa (zamówienie)</span><strong><?= pln($currentShippingCost, $currency) ?></strong></div>
                        <div class="mt-1 text-xs text-gray-500">
                            Waga zamówienia: <strong><?= number_format($orderTotalWeight, 2, ',', ' ') ?> kg</strong>
                            • Paczek: <strong><?= (int)$orderParcels ?></strong>
                            • Limit operacyjny: <?= number_format($operationalLimitKg, 2, ',', ' ') ?> kg
                        </div>
                        <div class="flex justify-between mt-1 text-base">
                            <span>Suma</span><strong><?= pln($total, $currency) ?></strong>
                        </div>
                    <?php else: ?>
                        <div class="mt-1 text-gray-500">
                            Waga zamówienia: <strong><?= number_format($orderTotalWeight, 2, ',', ' ') ?> kg</strong>
                            • Limit operacyjny: <?= number_format($operationalLimitKg, 2, ',', ' ') ?> kg
                            <br/>Wybierz metodę dostawy, aby zobaczyć pełną sumę.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Wybór dostawy -->
        <h2 class="text-lg font-bold mb-3">Metoda dostawy</h2>
        <?php if (empty($methods)): ?>
            <div class="p-3 bg-amber-50 border border-amber-300 text-amber-800 rounded">Brak dostępnych metod dostawy.</div>
        <?php else: ?>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES) ?>">
                <?php foreach ($methods as $m): ?>
                    <?php
                    $id = (int)$m['id'];
                    // Podgląd realnej ceny dla CAŁEGO zamówienia (konsolidacja wagowa)
                    $preview = calcOrderShippingConsolidated($pdo, $ownerId, $orderId, $id);
                    $previewPrice   = (float)($preview['total_cost'] ?? 0.0);
                    $previewParcels = (int)  ($preview['parcels']    ?? 0);
                    $previewLimit   = (float)($preview['limit_kg']   ?? 0.0);
                    $checked        = ($currentShippingId === $id) ? 'checked' : '';
                    ?>
                    <label class="flex items-center gap-3 border p-3 rounded-lg cursor-pointer hover:border-blue-400 transition <?= $checked ? 'ring-2 ring-blue-500' : '' ?>">
                        <input type="radio" name="shipping_method_id" value="<?= $id ?>" <?= $checked ?> class="h-4 w-4" onchange="highlightSelected(this)">
                        <div class="flex-1">
                            <div class="font-semibold"><?= shippingIcon((string)$m['name']) ?> <?= htmlspecialchars((string)$m['name']) ?></div>
                            <div class="text-gray-600 text-sm">
                                <?= $previewPrice == 0.0 ? '<span class="text-green-600 font-medium">Darmowa dostawa</span>' : pln($previewPrice, $currency) ?>
                            </div>
                            <div class="text-xs text-gray-500">
                                Waga: <?= number_format($orderTotalWeight, 2, ',', ' ') ?> kg • Paczek: <?= (int)$previewParcels ?> • Limit: <?= number_format($previewLimit, 2, ',', ' ') ?> kg
                            </div>
                        </div>
                    </label>
                <?php endforeach; ?>
                <button type="submit" class="w-full py-3 rounded-lg text-white font-semibold bg-gradient-to-r from-blue-500 to-indigo-600 hover:from-blue-600 hover:to-indigo-700">
                    Dalej ➡️
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        function highlightSelected(radio) {
            document.querySelectorAll('label').forEach(l => l.classList.remove('ring-2', 'ring-blue-500'));
            if (radio.checked) radio.closest('label').classList.add('ring-2', 'ring-blue-500');
        }
    </script>
</body>
</html>
