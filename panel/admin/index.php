<?php

declare(strict_types=1);
session_start();
if (empty($_SESSION['user']) || !isset($_SESSION['user']['id'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/admin/index.php');
    header("Location: /auth/login.php?redirect=$redirect");
    exit;
}
require_once __DIR__ . '/../../includes/login_check.php';

// ==============================
// admin/index.php — Olaj.pl V4
// Dashboard „Chuck Norris Edition” 😎
// ==============================

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../includes/db.php';

$owner_id   = (int)($_SESSION['user']['owner_id'] ?? 0);
$user_email = (string)($_SESSION['user']['email'] ?? '');
$user_role  = (string)($_SESSION['user']['role'] ?? '');

// ------ Zakres 7/30/60/90 dni ------
$days = (int)($_GET['days'] ?? 30);
if ($days < 7)  $days = 7;
if ($days > 90) $days = 90;

$fromDT = new DateTime('today -' . ($days - 1) . ' days');
$toDT   = new DateTime('tomorrow');

$from = $fromDT->format('Y-m-d 00:00:00');
$to   = $toDT->format('Y-m-d 00:00:00');

$todayStart     = (new DateTime('today'))->format('Y-m-d 00:00:00');
$tomorrow       = (new DateTime('tomorrow'))->format('Y-m-d 00:00:00');
$yesterdayStart = (new DateTime('yesterday'))->format('Y-m-d 00:00:00');

// --- Helpers: SQL ---
function tryQuery(PDO $pdo, string $sql, array $params): ?PDOStatement
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st;
    } catch (Throwable $e) {
        return null;
    }
}
function salesBetween(PDO $pdo, int $owner_id, string $from, string $to): float
{
    // V4: opieramy się o ukończony checkout (checkout_completed=1)
    $p = ['owner_id' => $owner_id, 'from' => $from, 'to' => $to];
    $v1 = "SELECT SUM(COALESCE(oi.unit_price,0)*COALESCE(oi.qty,0)) AS total
         FROM order_items oi
         JOIN order_groups og ON og.id=oi.order_group_id
         JOIN orders o ON o.id=og.order_id
         LEFT JOIN products p ON p.id=oi.product_id
         WHERE o.owner_id=:owner_id
           AND o.created_at>=:from AND o.created_at<:to
           AND og.checkout_completed=1";
    if ($st = tryQuery($pdo, $v1, $p)) return (float)($st->fetchColumn() ?: 0);

    // Fallback: płatność paid (gdy checkout_completed jeszcze nie wdrożony wszędzie)
    $v2 = str_replace(
        "AND og.checkout_completed=1",
        "AND EXISTS(SELECT 1 FROM payments pay WHERE pay.order_group_id=og.id AND pay.status IN('paid'))",
        $v1
    );
    if ($st = tryQuery($pdo, $v2, $p)) return (float)($st->fetchColumn() ?: 0);

    // Ostatecznie bez filtra (niezalecane, ale niech dashboard nie padnie)
    $v3 = str_replace("AND og.checkout_completed=1", "", $v1);
    $st = $pdo->prepare($v3);
    $st->execute($p);
    return (float)($st->fetchColumn() ?: 0);
}
function ordersCountBetween(PDO $pdo, int $owner_id, string $from, string $to): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE owner_id=:owner_id AND created_at>=:from AND created_at<:to");
    $st->execute(['owner_id' => $owner_id, 'from' => $from, 'to' => $to]);
    return (int)$st->fetchColumn();
}
function pctDelta($now, $prev): string
{
    if ($prev <= 0 && $now <= 0) return '0%';
    if ($prev <= 0) return '+∞';
    $d = (($now - $prev) / $prev) * 100;
    return ($d > 0 ? '+' : '') . number_format($d, 1, ',', ' ') . '%';
}

// KPI
$orders_today     = ordersCountBetween($pdo, $owner_id, $todayStart, $tomorrow);
$sales_today      = salesBetween($pdo, $owner_id, $todayStart, $tomorrow);

// V4: messages.direction='in' + status='pending' (zamiast parsed=0)
$new_messages     = (function (PDO $pdo, int $owner_id) {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM messages
     WHERE owner_id=:owner_id AND direction='in' AND status='pending'"
    );
    $st->execute(['owner_id' => $owner_id]);
    return (int)$st->fetchColumn();
})($pdo, $owner_id);

$orders_yesterday = ordersCountBetween($pdo, $owner_id, $yesterdayStart, $todayStart);
$sales_yesterday  = salesBetween($pdo, $owner_id, $yesterdayStart, $todayStart);
$orders_dd        = pctDelta($orders_today, $orders_yesterday);
$sales_dd         = pctDelta($sales_today, $sales_yesterday);

// Top produkty — V4: order_items.name + qty
$st = $pdo->prepare(
    "SELECT COALESCE(NULLIF(oi.name,''), p.name, 'Produkt') AS product_name,
          SUM(COALESCE(oi.qty,0)) AS total
   FROM order_items oi
   JOIN order_groups og ON og.id=oi.order_group_id
   JOIN orders o ON o.id=og.order_id
   LEFT JOIN products p ON p.id=oi.product_id
   WHERE o.owner_id=:owner_id AND o.created_at>=:from AND o.created_at<:to
   GROUP BY product_name
   ORDER BY total DESC
   LIMIT 5"
);
$st->execute(['owner_id' => $owner_id, 'from' => $from, 'to' => $to]);
$top_products = $st->fetchAll(PDO::FETCH_ASSOC);

// Breakdown płatności — V4: payments.status (draft/started/pending/paid/failed/cancelled)
$st = $pdo->prepare(
    "SELECT pay.status, COUNT(*) cnt
   FROM payments pay
   JOIN order_groups og ON og.id=pay.order_group_id
   JOIN orders o ON o.id=og.order_id
   WHERE o.owner_id=:owner_id AND o.created_at>=:from AND o.created_at<:to
   GROUP BY pay.status
   ORDER BY cnt DESC"
);
$st->execute(['owner_id' => $owner_id, 'from' => $from, 'to' => $to]);
$pay_breakdown = $st->fetchAll(PDO::FETCH_ASSOC);

// Serie (do startu strony)
$labels = [];
$yy = clone $fromDT;
while ($yy < $toDT) {
    $labels[] = $yy->format('Y-m-d');
    $yy->modify('+1 day');
}

$salesSeries = [];
$ordersSeries = [];
try {
    // V4: qty
    $st = $pdo->prepare(
        "SELECT DATE(o.created_at) d,
            SUM(COALESCE(oi.unit_price,0)*COALESCE(oi.qty,0)) v
     FROM order_items oi
     JOIN order_groups og ON og.id=oi.order_group_id
     JOIN orders o ON o.id=og.order_id
     LEFT JOIN products p ON p.id=oi.product_id
     WHERE o.owner_id=:owner_id AND o.created_at>=:from AND o.created_at<:to
     GROUP BY DATE(o.created_at)
     ORDER BY d"
    );
    $st->execute(['owner_id' => $owner_id, 'from' => $from, 'to' => $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $salesSeries[$r['d']] = (float)$r['v'];
    }
} catch (Throwable $e) {
}
try {
    $st = $pdo->prepare(
        "SELECT DATE(created_at) d, COUNT(*) v
     FROM orders
     WHERE owner_id=:owner_id AND created_at>=:from AND created_at<:to
     GROUP BY DATE(created_at)
     ORDER BY d"
    );
    $st->execute(['owner_id' => $owner_id, 'from' => $from, 'to' => $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ordersSeries[$r['d']] = (int)$r['v'];
    }
} catch (Throwable $e) {
}

$salesChart = [];
$ordersChart = [];
foreach ($labels as $d) {
    $salesChart[] = (float)($salesSeries[$d] ?? 0);
    $ordersChart[] = (int)($ordersSeries[$d] ?? 0);
}

$labelsJS      = json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$salesChartJS  = json_encode($salesChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$ordersChartJS = json_encode($ordersChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$payLabelsJS   = json_encode(array_map(fn($r) => (string)$r['status'], $pay_breakdown), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$payDataJS     = json_encode(array_map(fn($r) => (int)$r['cnt'], $pay_breakdown), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// === PROGRESS HELPERS ===
function pct_delta_text(float $now, float $prev): string
{
    if ($prev <= 0 && $now <= 0) return '0%';
    if ($prev <= 0) return '+∞';
    $d = (($now - $prev) / $prev) * 100;
    $sign = $d > 0 ? '+' : '';
    return $sign . number_format($d, 1, ',', ' ') . '%';
}
/** 0–200% porównania vs wczoraj; do paska bierzemy min(100, %). */
function ratio_pct(float $now, float $prev): int
{
    if ($prev <= 0) return $now > 0 ? 200 : 0;
    $pct = ($now / $prev) * 100;
    if (!is_finite($pct)) $pct = 0;
    return max(0, min(200, (int)round($pct)));
}

$orders_progress = ratio_pct((float)$orders_today, (float)$orders_yesterday);
$sales_progress  = ratio_pct((float)$sales_today,  (float)$sales_yesterday);
$orders_up = $orders_today >= $orders_yesterday;
$sales_up  = $sales_today  >= $sales_yesterday;

$messages_progress   = (int)max(0, 100 - min(100, round(($new_messages / 20) * 100)));
$messages_bar_class  = $new_messages == 0 ? 'bg-green-500' : ($new_messages <= 5 ? 'bg-yellow-400' : 'bg-red-400');
$orders_bar_class    = $orders_up ? 'bg-green-500' : 'bg-red-400';
$sales_bar_class     = $sales_up  ? 'bg-green-500' : 'bg-red-400';
$orders_dd_text      = pct_delta_text((float)$orders_today, (float)$orders_yesterday);
$sales_dd_text       = pct_delta_text((float)$sales_today,  (float)$sales_yesterday);
?>
<?php include __DIR__ . '/../layout/layout_header.php'; ?>

<div class="p-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <h1 class="text-2xl font-bold">📊 Dashboard</h1>
        <div class="flex items-center gap-2">
            <?php foreach ([7, 30, 60] as $opt): ?>
                <a href="?days=<?= $opt ?>"
                    class="px-3 py-1.5 rounded-lg border <?= $days === $opt ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                    Ostatnie <?= $opt ?> dni
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- KAFLE KPI -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
        <!-- 📦 Zamówienia -->
        <div class="bg-white p-3 rounded-lg shadow flex flex-col items-center justify-center min-h-[110px]" title="Liczba zamówień dzisiaj vs wczoraj">
            <div class="text-xs text-gray-500">📦 Zamówień dzisiaj</div>
            <div class="text-xl font-bold" data-countup="<?= (int)$orders_today ?>"></div>
            <div class="text-[11px] text-gray-400">vs wczoraj <?= (int)$orders_yesterday ?> (<?= htmlspecialchars($orders_dd_text) ?>)</div>
            <div class="w-full mt-2">
                <div class="h-2 w-full bg-gray-100 rounded-full overflow-hidden" title="Postęp vs wczoraj: <?= $orders_progress ?>%">
                    <div class="h-full <?= $orders_bar_class ?> rounded-full" style="width: <?= min(100, $orders_progress) ?>%"></div>
                </div>
                <div class="text-[10px] text-gray-400 mt-1 text-right"><?= $orders_progress ?>% vs wczoraj</div>
            </div>
        </div>

        <!-- 💰 Sprzedaż -->
        <div class="bg-white p-3 rounded-lg shadow flex flex-col items-center justify-center min-h-[110px]" title="Suma wartości sprzedanych pozycji (checkout_completed=1)">
            <div class="text-xs text-gray-500">💰 Sprzedaż dzisiaj</div>
            <div class="text-xl font-bold" data-moneyup="<?= number_format($sales_today, 2, '.', '') ?>"></div>
            <div class="text-[11px] text-gray-400">
                vs <?= number_format($sales_yesterday, 2, ',', ' ') ?> zł (<?= htmlspecialchars($sales_dd_text) ?>)
            </div>
            <div class="w-full mt-2">
                <div class="h-2 w-full bg-gray-100 rounded-full overflow-hidden" title="Postęp vs wczoraj: <?= $sales_progress ?>%">
                    <div class="h-full <?= $sales_bar_class ?> rounded-full" style="width: <?= min(100, $sales_progress) ?>%"></div>
                </div>
                <div class="text-[10px] text-gray-400 mt-1 text-right"><?= $sales_progress ?>% vs wczoraj</div>
            </div>
        </div>

        <!-- 💬 Wiadomości (mniej = lepiej) -->
        <div class="bg-white p-3 rounded-lg shadow flex flex-col items-center justify-center min-h-[110px]" title="Wiadomości IN w statusie pending">
            <div class="text-xs text-gray-500">💬 Nowe wiadomości</div>
            <div class="text-xl font-bold"><?= (int)$new_messages ?></div>
            <div class="text-[11px] text-gray-400">nieprzetworzone (in)</div>
            <div class="w-full mt-2">
                <div class="h-2 w-full bg-gray-100 rounded-full overflow-hidden" title="Im pełniej, tym bliżej zera">
                    <div class="h-full <?= $messages_bar_class ?> rounded-full" style="width: <?= $messages_progress ?>%"></div>
                </div>
                <div class="text-[10px] text-gray-400 mt-1 text-right">
                    <?= $messages_progress ?>% do celu (0 msg)
                </div>
            </div>
        </div>

        <!-- 👋 Witaj -->
        <div class="bg-white p-3 rounded-lg shadow flex flex-col items-center justify-center min-h-[110px]" title="Zalogowany użytkownik">
            <div class="text-xs text-gray-500">👋 Witaj</div>
            <div class="text-xl font-bold truncate max-w-[90%]"><?= htmlspecialchars($user_email) ?></div>
            <div class="text-[11px] text-gray-400"><?= htmlspecialchars($user_role) ?> • owner_id: <?= (int)$owner_id ?></div>
            <div class="w-full mt-2">
                <div class="h-2 w-full bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-blue-400 rounded-full" style="width: 100%"></div>
                </div>
                <div class="text-[10px] text-gray-400 mt-1 text-right">online</div>
            </div>
        </div>
    </div>

    <!-- WYKRESY -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-10">
        <div class="bg-white rounded-2xl p-4 shadow lg:col-span-2">
            <div class="flex items-center justify-between mb-2">
                <div class="font-semibold">📈 Sprzedaż vs. Zamówienia (ostatnie <?= $days ?> dni)</div>
            </div>
            <canvas id="chartSalesOrders" height="100"></canvas>
        </div>

        <div class="bg-white rounded-2xl p-4 shadow">
            <div class="flex items-center justify-between mb-2">
                <div class="font-semibold">💳 Statusy płatności (<?= $days ?> dni)</div>
            </div>
            <canvas id="chartPayments" height="100"></canvas>
            <?php if (empty($pay_breakdown)): ?>
                <div class="text-xs text-gray-500 mt-2">Brak danych o płatnościach w wybranym zakresie.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- HEATMAPA GODZINOWA 24×7 -->
    <div class="bg-white rounded-2xl p-4 shadow mb-10">
        <div class="flex items-center justify-between mb-2">
            <div class="font-semibold">🔥 Godziny aktywności (ostatnie <?= $days ?> dni)</div>
            <div class="text-xs text-gray-500">Im ciemniej, tym więcej zamówień</div>
        </div>
        <div id="heatmapGrid" class="grid" style="grid-template-columns: 60px repeat(24, minmax(12px, 1fr)); gap: 2px;"></div>
    </div>
    <!-- HOT QUEUE CW -->
    <div class="bg-white rounded-2xl p-4 shadow mb-10">
        <div class="flex items-center justify-between mb-2">
            <div class="font-semibold">🔥 Hot Queue (CW)</div>
            <a href="/admin/cw/" class="text-xs text-blue-600 underline">Zobacz wszystkie</a>
        </div>
        <div id="hot-queue" class="flex flex-wrap gap-2 text-sm text-gray-700">
            <div class="text-gray-400">⏳ Ładowanie...</div>
        </div>
    </div>

    <script>
        async function loadHotQueue() {
            const box = document.getElementById('hot-queue');
            box.innerHTML = '<div class="text-gray-400">⏳ Ładowanie...</div>';
            try {
                const [errs, queued] = await Promise.all([
                    fetch('/admin/api/notifications.php?list=cw_errors&limit=5', {
                        credentials: 'same-origin'
                    }).then(r => r.json()).catch(() => []),
                    fetch('/admin/api/notifications.php?list=cw_queue&limit=5', {
                        credentials: 'same-origin'
                    }).then(r => r.json()).catch(() => []),
                ]);
                box.innerHTML = '';

                function pill(msg, type) {
                    const span = document.createElement('span');
                    span.className = 'px-2 py-1 rounded-full text-xs font-medium';
                    span.textContent = `${msg.channel||'❓'} • ${msg.status}`;
                    if (type === 'error') span.classList.add('bg-red-100', 'text-red-700');
                    else span.classList.add('bg-yellow-100', 'text-yellow-700');
                    return span;
                }
                let n = 0;
                (errs?.cw_errors || []).forEach(m => {
                    box.appendChild(pill(m, 'error'));
                    n++;
                });
                (queued?.cw_queue || []).forEach(m => {
                    box.appendChild(pill(m, 'queued'));
                    n++;
                });
                if (!n) box.innerHTML = '<div class="text-gray-400">✅ Kolejka pusta</div>';
            } catch (e) {
                box.innerHTML = '<div class="text-red-400">⚠️ Błąd ładowania kolejki</div>';
            }
        }
        loadHotQueue();
        setInterval(loadHotQueue, 60000);
    </script>

    <!-- STATUS PACZEK (V4, PL ENUM) -->
    <?php
    // Kafelki zgodne ze schematem V4 (PL):
    // - "nieopłacone" liczymy z og.paid_status in ('nieopłacona','częściowa') — DISTINCT orders
    $tiles = [
        'nowe'                => '📥 Nowe',
        'otwarta_paczka'      => '📦 Otwarta paczka',
        'oczekuje_na_płatność' => '💸 Czeka na płatność',
        'gotowe_do_wysyłki'   => '📤 Do wysyłki',
        'wysłane'             => '🚚 Wysłane',
        'zrealizowane'        => '✅ Zrealizowane',
        'anulowane'           => '❌ Anulowane',
        'nieopłacone'         => '💳 Nieopłacone',
    ];

    $order_status_counts = array_fill_keys(array_keys($tiles), 0);

    // 1) Proste statusy (pojedyncza wartość)
    foreach (['nowe', 'oczekuje_na_płatność', 'gotowe_do_wysyłki', 'wysłane', 'zrealizowane', 'anulowane'] as $st) {
        $q = "SELECT COUNT(*) FROM orders WHERE owner_id=:oid AND order_status=:st";
        $stmt = $pdo->prepare($q);
        $stmt->execute(['oid' => $owner_id, 'st' => $st]);
        $order_status_counts[$st] = (int)$stmt->fetchColumn();
    }

    // 2) Otwarta paczka: 'otwarta_paczka' lub dowolny wariant z prefiksem
    $q = "SELECT COUNT(*) FROM orders
        WHERE owner_id=:oid
          AND (order_status='otwarta_paczka' OR order_status LIKE 'otwarta_paczka:%')";
    $stmt = $pdo->prepare($q);
    $stmt->execute(['oid' => $owner_id]);
    $order_status_counts['otwarta_paczka'] = (int)$stmt->fetchColumn();

    // 3) Nieopłacone: DISTINCT orders z grupami nieopłaconymi/częściowymi
    $q = "SELECT COUNT(DISTINCT o.id) FROM orders o
        JOIN order_groups og ON og.order_id=o.id
        WHERE o.owner_id=:oid
          AND og.paid_status IN ('nieopłacona','częściowa')";
    $stmt = $pdo->prepare($q);
    $stmt->execute(['oid' => $owner_id]);
    $order_status_counts['nieopłacone'] = (int)$stmt->fetchColumn();
    ?>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3 mb-10">
        <?php foreach ($tiles as $statusKey => $label): ?>
            <a href="/admin/orders/?status=<?= urlencode($statusKey) ?>"
                title="Pokaż zamówienia: <?= htmlspecialchars($label) ?>"
                class="rounded-xl shadow bg-white p-4 text-center hover:bg-gray-50">
                <div class="text-base"><?= $label ?></div>
                <div class="text-2xl font-bold" data-countup="<?= (int)($order_status_counts[$statusKey] ?? 0) ?>">0</div>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- AI INSIGHTS -->
    <div id="ai-insights" class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-8 text-sm">
        🔍 <strong>AI Insights:</strong> <span id="ai-insights-data" class="ml-2 text-gray-700">Ładowanie sugestii...</span>
        <button id="ai-refresh" class="ml-3 px-2 py-1 text-xs rounded border border-blue-300 hover:bg-blue-100">Odśwież</button>
        <a href="/admin/ai-insights.php" class="ml-3 text-xs text-blue-700 underline">Zobacz wszystkie</a>
    </div>

    <!-- TOP PRODUKTY -->
    <div class="bg-white rounded-2xl p-4 shadow mb-8">
        <div class="font-semibold mb-2">🔝 Top 5 produktów (<?= $days ?> dni)</div>
        <?php if (!$top_products): ?>
            <div class="text-sm text-gray-500">Brak danych.</div>
        <?php else: ?>
            <ul class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
                <?php foreach ($top_products as $p): ?>
                    <li class="flex items-center justify-between gap-3 p-3 rounded-lg border">
                        <span class="truncate"><?= htmlspecialchars($p['product_name'] ?? 'Produkt') ?></span>
                        <span class="text-sm font-semibold bg-gray-100 px-2 py-0.5 rounded"><?= (int)($p['total'] ?? 0) ?> szt.</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- SKRÓTY SYSTEMOWE -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-4 gap-4">
        <a href="/admin/orders/" class="bg-white p-4 rounded-xl shadow hover:bg-gray-50 text-center">➕<br>Nowe zamówienie</a>
        <a href="/admin/clients/" class="bg-white p-4 rounded-xl shadow hover:bg-gray-50 text-center">👤<br>Dodaj klienta</a>
        <a href="/live/view.php" class="bg-white p-4 rounded-xl shadow hover:bg-gray-50 text-center">🎥<br>Start live'a</a>
        <a href="/admin/messages/" class="bg-white p-4 rounded-xl shadow hover:bg-gray-50 text-center">💬<br>Wiadomości</a>
    </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Animowane liczniki
    (function() {
        function animateCount(el, target, fmt) {
            const dur = 800,
                start = 0;
            const t0 = performance.now();

            function tick(t) {
                const p = Math.min(1, (t - t0) / dur);
                const val = start + (target - start) * p;
                el.textContent = fmt ? fmt(val) : Math.round(val).toString();
                if (p < 1) requestAnimationFrame(tick);
            }
            requestAnimationFrame(tick);
        }
        document.querySelectorAll('[data-countup]').forEach(el => {
            animateCount(el, parseFloat(el.getAttribute('data-countup') || '0'));
        });
        document.querySelectorAll('[data-moneyup]').forEach(el => {
            const v = parseFloat(el.getAttribute('data-moneyup') || '0');
            animateCount(el, v, x => (x).toFixed(2).replace('.', ',') + ' zł');
        });
    })();
</script>

<script>
    // Wykresy startowe
    (function() {
        const labels = <?= $labelsJS ?>;
        const sales = <?= $salesChartJS ?>;
        const orders = <?= $ordersChartJS ?>;

        const ctx1 = document.getElementById('chartSalesOrders').getContext('2d');
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                        type: 'line',
                        label: 'Sprzedaż',
                        data: sales,
                        yAxisID: 'y',
                        tension: 0.25
                    },
                    {
                        type: 'bar',
                        label: 'Zamówienia',
                        data: orders,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ctx.dataset.type === 'line' ?
                                `Sprzedaż: ${ctx.parsed.y.toLocaleString('pl-PL',{minimumFractionDigits:2, maximumFractionDigits:2})} zł` : `Zamówienia: ${ctx.parsed.y}`
                        }
                    }
                },
                scales: {
                    y: {
                        position: 'left',
                        ticks: {
                            callback: v => v.toLocaleString('pl-PL') + ' zł'
                        }
                    },
                    y1: {
                        position: 'right',
                        grid: {
                            drawOnChartArea: false
                        }
                    },
                    x: {
                        ticks: {
                            callback: (v, i) => labels[i].slice(5)
                        }
                    }
                }
            }
        });

        const payLabels = <?= $payLabelsJS ?>;
        const payData = <?= $payDataJS   ?>;
        if (document.getElementById('chartPayments') && payLabels.length) {
            new Chart(document.getElementById('chartPayments').getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: payLabels,
                    datasets: [{
                        data: payData
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    },
                    cutout: '60%'
                }
            });
        }

        // AI Insights (XSS-safe)
        function loadAI() {
            const el = document.getElementById('ai-insights-data');
            el.textContent = 'Ładowanie...';
            fetch('/admin/api/ai_last_report.php', {
                    credentials: 'same-origin'
                })
                .then(r => r.ok ? r.json() : Promise.reject())
                .then(data => {
                    el.textContent = '';
                    if (!data || !data.data) {
                        el.textContent = 'Brak danych AI.';
                        return;
                    }
                    const prefix = (data.context_type === 'live') ? '🎥 Live #' : '📦 Zamówienie #';
                    const head = document.createElement('div');
                    head.textContent = `Ostatni raport: ${prefix}${data.context_id}`;
                    el.appendChild(head);
                    const sugg = Array.isArray(data.data.suggestions) ? data.data.suggestions : [];
                    if (sugg.length) {
                        const ul = document.createElement('ul');
                        ul.className = 'list-disc ml-5';
                        for (const s of sugg) {
                            const li = document.createElement('li');
                            li.textContent = String(s ?? '');
                            ul.appendChild(li);
                        }
                        el.appendChild(ul);
                    } else {
                        const p = document.createElement('span');
                        p.textContent = 'Brak sugestii.';
                        el.appendChild(p);
                    }
                })
                .catch(() => {
                    el.textContent = 'Błąd ładowania danych AI.';
                });
        }
        document.getElementById('ai-refresh')?.addEventListener('click', loadAI);
        loadAI();
    })();
</script>

<script>
    // AUTO REFRESH + HEATMAP + SPARKLINES (co 60s, z debounce)
    (function() {
        const days = <?= (int)$days ?>;
        let charts = {
            main: null,
            donut: null
        };
        let refreshTimer = null;

        function debounce(fn, ms) {
            let h;
            return (...a) => {
                clearTimeout(h);
                h = setTimeout(() => fn(...a), ms);
            };
        }

        function renderHeatmap(heat) {
            const grid = document.getElementById('heatmapGrid');
            grid.innerHTML = '';
            const daysLabels = ['Pon', 'Wt', 'Śr', 'Czw', 'Pt', 'Sob', 'Ndz'];

            const head = document.createElement('div');
            head.textContent = '';
            head.className = 'text-xs text-gray-500 p-1';
            grid.appendChild(head);
            for (let h = 0; h < 24; h++) {
                const c = document.createElement('div');
                c.className = 'text-[10px] text-gray-500 text-center';
                c.textContent = h.toString().padStart(2, '0');
                grid.appendChild(c);
            }
            let max = 1;
            for (let d = 0; d < 7; d++)
                for (let h = 0; h < 24; h++) max = Math.max(max, heat?.[d]?.[h] ?? 0);

            for (let d = 0; d < 7; d++) {
                const label = document.createElement('div');
                label.className = 'text-xs text-gray-600 p-1 pr-2 sticky left-0 bg-white';
                label.textContent = daysLabels[d];
                grid.appendChild(label);
                for (let h = 0; h < 24; h++) {
                    const v = heat?.[d]?.[h] ?? 0;
                    const cell = document.createElement('div');
                    const alpha = v === 0 ? 0 : (0.15 + 0.85 * (v / max));
                    cell.style.backgroundColor = `rgba(59,130,246,${alpha})`;
                    cell.title = `${daysLabels[d]} ${h.toString().padStart(2,'0')}:00 → ${v} zam.`;
                    cell.className = 'h-6 rounded-sm';
                    grid.appendChild(cell);
                }
            }
        }

        function renderSparklines(labels, ordersDaily, salesDaily) {
            const sales = labels.map(d => +(salesDaily[d] ?? 0));
            const orders = labels.map(d => +(ordersDaily[d] ?? 0));

            function attachSpark(boxEl, data) {
                if (!boxEl) return;
                let cnv = boxEl.querySelector('canvas.spark');
                if (!cnv) {
                    cnv = document.createElement('canvas');
                    cnv.className = 'spark mt-1';
                    cnv.height = 40;
                    boxEl.appendChild(cnv);
                }
                const prev = Chart.getChart(cnv);
                if (prev) prev.destroy();
                new Chart(cnv.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [{
                            data,
                            pointRadius: 0,
                            borderWidth: 1,
                            tension: 0.25
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        responsive: true,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                enabled: false
                            }
                        },
                        scales: {
                            x: {
                                display: false
                            },
                            y: {
                                display: false
                            }
                        }
                    }
                });
            }
            const kpis = document.querySelectorAll('.bg-white.p-3.rounded-lg.shadow'); // pierwsze 2 kafle to zam/sprz
            attachSpark(kpis[0], orders);
            attachSpark(kpis[1], sales);
        }

        function updateUI(payload) {
            // Update liczb dziennych (jeśli backend zwróci)
            if (typeof payload.orders_today === 'number') {
                const el = document.querySelector('[data-countup]');
                if (el) el.textContent = payload.orders_today.toString();
            }
            if (typeof payload.sales_today === 'number') {
                const el = document.querySelector('[data-moneyup]');
                if (el) el.textContent = payload.sales_today.toFixed(2).replace('.', ',') + ' zł';
            }

            const labels = payload.labels || [];
            const sales = labels.map(d => +(payload.salesDaily?.[d] ?? 0));
            const orders = labels.map(d => +(payload.ordersDaily?.[d] ?? 0));

            const ctx1 = document.getElementById('chartSalesOrders')?.getContext('2d');
            if (ctx1) {
                const chart = Chart.getChart(ctx1.canvas);
                if (chart) {
                    chart.data.labels = labels;
                    chart.data.datasets[0].data = sales;
                    chart.data.datasets[1].data = orders;
                    chart.update();
                }
            }

            const ctx2 = document.getElementById('chartPayments')?.getContext('2d');
            if (ctx2) {
                const chart = Chart.getChart(ctx2.canvas);
                if (chart && payload.payments) {
                    chart.data.labels = payload.payments.map(p => p.status);
                    chart.data.datasets[0].data = payload.payments.map(p => +p.cnt);
                    chart.update();
                }
            }

            renderHeatmap(payload.heatmap || []);
            renderSparklines(labels, payload.ordersDaily || {}, payload.salesDaily || {});
        }

        const doRefresh = debounce(async function() {
            try {
                const res = await fetch(`/admin/api/dashboard_metrics.php?days=${days}`, {
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (!data.ok) throw 0;
                updateUI(data);
            } catch (e) {
                console.warn('Dashboard refresh fail', e);
            }
        }, 200);

        doRefresh();
        refreshTimer = setInterval(doRefresh, 60000);
        window.addEventListener('visibilitychange', () => {
            if (!document.hidden) doRefresh();
        });
    })();
</script>

<?php include __DIR__ . '/../layout/layout_footer.php'; ?>