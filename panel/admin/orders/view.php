<?php
// admin/orders/view.php — szczegóły zamówienia (Olaj.pl V4)
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';

use Engine\Orders\ViewRenderer;
use Engine\Enum\OrderStatus;

// ───────────────────────────────────────────────────────────────
// Wejście + CSRF
// ───────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

$orderId = (int)($_GET['id'] ?? 0);
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($orderId <= 0 || $ownerId <= 0) {
    http_response_code(400);
    exit('❌ Brak wymaganych parametrów (orderId/ownerId).');
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

require_once __DIR__ . '/../../layout/layout_header.php';

// ───────────────────────────────────────────────────────────────
// Helpery
// ───────────────────────────────────────────────────────────────
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function zl(float $v): string
{
    return number_format($v, 2, ',', ' ') . ' zł';
}

// ───────────────────────────────────────────────────────────────
// Dane zamówienia
// ───────────────────────────────────────────────────────────────
$data = ViewRenderer::loadOrderData($pdo, $orderId, $ownerId);
if (!$data) {
    http_response_code(404);
    exit('❌ Zamówienie nie istnieje lub brak dostępu.');
}

$order        = $data['order'] ?? [];
$groups       = $data['groups'] ?? [];
$itemsByGroup = $data['itemsByGroup'] ?? [];
$dues         = $data['dues'] ?? [];
$paid         = $data['paid'] ?? [];
$applied      = $data['appliedPaid'] ?? [];
$firstAddress = $data['firstAddress'] ?? null;
$shippingName = $data['shippingName'] ?? null;
$canEdit      = !empty($order) && (int)($order['checkout_completed'] ?? 0) !== 1;

// Agregaty płatności
$totalDue = 0.0;
$totalPaid = 0.0;
$eps = 0.01;
foreach ($groups as $g) {
    $gid = (int)$g['id'];
    $totalDue  += (float)($dues[$gid] ?? 0.0);
    $totalPaid += (float)($paid[$gid] ?? 0.0);
}
$aggPayStatus = ($totalPaid <= $eps)
    ? 'nieopłacona'
    : (($totalPaid + $eps < $totalDue)
        ? 'częściowa'
        : ((abs($totalPaid - $totalDue) <= $eps) ? 'opłacona' : 'nadpłata'));

// Waga (opcjonalnie)
$totalWeightKg = 0.0;
try {
    $stmtW = $pdo->prepare("
        SELECT COALESCE(SUM(p.weight_kg),0)
        FROM packages p
        JOIN shipping_labels sl ON sl.id=p.label_id
        WHERE sl.order_id=?
    ");
    $stmtW->execute([$orderId]);
    $totalWeightKg = (float)$stmtW->fetchColumn();
} catch (Throwable $__) {
    $totalWeightKg = (float)($order['order_weight_kg'] ?? 0.0);
}

// ───────────────────────────────────────────────────────────────
// Zakładki
// ───────────────────────────────────────────────────────────────
$tabs = [
    'overview' => 'Przegląd',
    'payments' => 'Płatności',
    'shipping' => 'Wysyłki',
    'logs'     => 'Logi',
];
$active = $_GET['tab'] ?? 'overview';
if (!isset($tabs[$active])) $active = 'overview';
?>
<div class="p-4 md:p-6">
    <div class="flex items-start justify-between gap-3 mb-5">
        <div class="min-w-0">
            <div class="flex items-center gap-3 flex-wrap">
                <h1 class="text-xl md:text-2xl font-semibold">Zamówienie #<?= (int)$order['id'] ?></h1>
                <div><?= ViewRenderer::renderStatusBadge((string)($order['order_status'] ?? '')) ?></div>
                <div><?= ViewRenderer::renderPayChip($aggPayStatus) ?></div>
                <div><?= ViewRenderer::renderWeightBadge((float)$totalWeightKg) ?></div>
            </div>
            <div class="text-stone-500 mt-1 text-sm">
                Utworzone: <?= e((string)($order['created_at'] ?? '')) ?>
                • Ostatnia aktualizacja: <?= e((string)($order['updated_at'] ?? '')) ?>
            </div>
            <?php if ((int)($order['checkout_completed'] ?? 0) === 1): ?>
                <div class="mt-2 inline-flex items-center gap-2 px-2.5 py-1 rounded-lg bg-stone-100 border text-stone-700 text-sm">
                    🔒 Zamówienie zafinalizowane — edycja zablokowana (Checkout V2).
                </div>
            <?php endif; ?>
        </div>

        <div class="flex items-center gap-2">
            <a href="/admin/orders/index.php" class="px-3 py-2 rounded-lg border border-stone-300 hover:bg-stone-100">← Lista</a>
            <?php if ($canEdit): ?>
                <form method="post" action="/admin/orders/api/change_status.php" class="inline">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                    <select name="status" class="px-3 py-2 rounded-lg border border-stone-300">
                        <?php
                        // Zaciągnięte z enuma (ENG values → PL labels)
                        $cur = (string)($order['order_status'] ?? '');
                        foreach (OrderStatus::ALL as $val) {
                            $label = OrderStatus::getLabel($val);
                            $sel = $val === $cur ? 'selected' : '';
                            echo '<option value="' . e($val) . '" ' . $sel . '>' . e($label) . '</option>';
                        }
                        ?>
                    </select>
                    <button class="px-3 py-2 rounded-lg bg-stone-900 text-white hover:bg-stone-800">Zmień status</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Zakładki -->
    <div class="border-b border-stone-200 mb-4">
        <nav class="-mb-px flex flex-wrap gap-2 text-sm">
            <?php foreach ($tabs as $k => $label):
                $is = $active === $k;
                $href = '/admin/orders/view.php?' . http_build_query(['id' => $orderId, 'tab' => $k]); ?>
                <a href="<?= e($href) ?>"
                    class="px-3 py-2 <?= $is ? 'border-b-2 border-stone-900 text-stone-900' : 'text-stone-500 hover:text-stone-900' ?>">
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <?php if ($active === 'overview'): ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 space-y-4">
                <!-- Klient + adres -->
                <div class="rounded-xl border border-stone-200">
                    <div class="px-4 py-3 border-b bg-stone-50 font-semibold">Klient i dostawa</div>
                    <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        <div>
                            <div class="text-stone-500">Klient</div>
                            <div class="font-medium"><?= e((string)($order['client_name'] ?? '—')) ?></div>
                            <div class="text-stone-600">
                                <?= e((string)($order['client_email'] ?? '')) ?>
                                <?= !empty($order['client_phone']) ? ' • ' . e((string)$order['client_phone']) : '' ?>
                            </div>
                            <?php if (!empty($order['client_token'])): ?>
                                <div class="text-stone-500 mt-1">
                                    Token: <code><?= e((string)$order['client_token']) ?></code>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="text-stone-500">Dostawa</div>
                            <div><?= $shippingName ? e($shippingName) : '—' ?></div>
                            <?php if ($firstAddress): ?>
                                <div class="mt-1 text-stone-700">
                                    <?= e((string)($firstAddress['full_name'] ?? '')) ?><br>
                                    <?= e((string)($firstAddress['street'] ?? '')) ?><br>
                                    <?= e((string)($firstAddress['postal_code'] ?? '')) ?>
                                    <?= e((string)($firstAddress['city'] ?? '')) ?><br>
                                    <?= e((string)($firstAddress['country'] ?? '')) ?>
                                </div>
                            <?php else: ?>
                                <div class="text-stone-500">Brak adresu.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Skaner + Grupy -->
                <?php
                require __DIR__ . '/partials/_overview_scan.php';
                $canEditLocal = $canEdit;
                require __DIR__ . '/partials/_overview_groups.php';
                require __DIR__ . '/partials/_overview_groups_js.php';
                ?>

            </div>

            <!-- Podsumowanie -->
            <div class="space-y-4">
                <div class="rounded-xl border border-stone-200">
                    <div class="px-4 py-3 border-b bg-stone-50 font-semibold">Szybkie podsumowanie</div>
                    <div class="p-4 text-sm space-y-1">
                        <div class="flex justify-between"><span>Wartość pozycji:</span><b><?= zl($totalDue) ?></b></div>
                        <div class="flex justify-between"><span>Zapłacono:</span><b><?= zl($totalPaid) ?></b></div>
                        <div class="flex justify-between"><span>Status płatności:</span><b class="lowercase"><?= e($aggPayStatus) ?></b></div>
                        <div class="flex justify-between"><span>Grupy:</span><b><?= count($groups) ?></b></div>
                        <div class="flex justify-between"><span>Checkout ukończony:</span><b><?= (int)($order['checkout_completed'] ?? 0) ? 'Tak' : 'Nie' ?></b></div>
                        <div class="mt-3"><?= ViewRenderer::renderPaymentWidget($pdo, $orderId, $ownerId) ?></div>
                    </div>
                </div>

                <?php
                // Przyciski InPost — poprawka: wybór grupy + CSRF
                $firstGroupId = isset($groups[0]['id']) ? (int)$groups[0]['id'] : 0;
                ?>
                <div class="space-y-2">
                    <label class="block text-sm text-stone-600">Grupa do wysyłki</label>
                    <select id="inpostGroupSelect" class="w-full px-3 py-2 rounded-lg border border-stone-300">
                        <?php if (!$groups): ?>
                            <option value="">Brak grup</option>
                        <?php else: foreach ($groups as $g): 
                            $gid = (int)$g['id'];
                            $label = '#'.$gid.' • '.htmlspecialchars((string)($g['name'] ?? 'Paczka'), ENT_QUOTES, 'UTF-8');
                        ?>
                            <option 
                                value="<?= $gid ?>"
                                data-method-id="<?= (int)($g['shipping_method_id'] ?? 0) ?>"
                                <?= $gid === $firstGroupId ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; endif; ?>
                    </select>

                    <button 
                        class="btn btn-primary w-full"
                        data-inpost-create
                        data-order-id="<?= (int)$order['id'] ?>"
                        <?= !$groups ? 'disabled' : '' ?>>
                        Utwórz etykietę InPost
                    </button>
                </div>

                <script>
                    document.addEventListener('click', async (e) => {
                        const b = e.target.closest('[data-inpost-create]');
                        if (!b) return;

                        const sel = document.getElementById('inpostGroupSelect');
                        const groupId = sel?.value || '';
                        const methodId = sel?.selectedOptions?.[0]?.dataset?.methodId || '';

                        if (!groupId) { alert('Wybierz grupę.'); return; }

                        const body = new URLSearchParams({
                            order_id: b.dataset.orderId,
                            order_group_id: groupId,
                            shipping_method_id: methodId,
                            csrf: '<?= e($csrf) ?>'
                        });

                        b.disabled = true;
                        try {
                            const r = await fetch('/admin/api/inpost_create_shipment.php', {
                                method: 'POST',
                                headers: {
                                    'X-CSRF-Token': '<?= e($csrf) ?>'
                                },
                                body
                            });
                            const j = await r.json();
                            if (!j.ok) throw new Error(j.error || 'Błąd InPost');
                            alert('OK! Tracking: ' + (j.tracking_number || '–'));
                            // TODO: odśwież listę etykiet (AJAX) i pokaż j.label_url / j.tracking_number
                        } catch (err) {
                            alert('Błąd: ' + err.message);
                        } finally {
                            b.disabled = false;
                        }
                    });
                </script>

            </div>
        </div>
    <?php endif; ?>

    <?php if ($active === 'payments'): ?>
        <div class="rounded-xl border border-stone-200 overflow-hidden">
            <div class="px-4 py-3 border-b bg-stone-50 font-semibold">Płatności</div>
            <div class="p-4"><?= ViewRenderer::renderPaymentWidget($pdo, $orderId, $ownerId) ?></div>
        </div>
        <?php ViewRenderer::renderPaymentModal($pdo, $orderId, $ownerId, $csrf); ?>
    <?php endif; ?>

    <?php if ($active === 'shipping'): ?>
        <div class="rounded-xl border border-stone-200 overflow-hidden">
            <div class="px-4 py-3 border-b bg-stone-50 font-semibold">Etykiety i paczki</div>
            <div class="p-4 text-sm space-y-3">
                <?php
                $labels = [];
                try {
                    $stmtL = $pdo->prepare("SELECT * FROM shipping_labels WHERE order_id = ? ORDER BY id DESC");
                    $stmtL->execute([$orderId]);
                    $labels = $stmtL->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable $__) {
                }
                ?>
                <?php if (!$labels): ?>
                    <div class="text-stone-500">Brak wygenerowanych etykiet.</div>
                <?php else: foreach ($labels as $lab): ?>
                    <div class="p-3 border rounded-lg">
                        <div class="flex items-center justify-between">
                            <div class="font-medium">
                                #<?= (int)$lab['id'] ?> • <?= e((string)($lab['carrier'] ?? '')) ?> • <?= e((string)($lab['status'] ?? 'pending')) ?>
                            </div>
                            <div class="text-stone-500"><?= e((string)($lab['created_at'] ?? '')) ?></div>
                        </div>
                        <?php if (!empty($lab['tracking_number'])): ?>
                            <div class="mt-1">Tracking: <b><?= e((string)$lab['tracking_number']) ?></b></div>
                        <?php endif; ?>
                        <?php if (!empty($lab['label_url'])): ?>
                            <div class="mt-1"><a class="text-blue-700 hover:underline" href="<?= e((string)$lab['label_url']) ?>" target="_blank" rel="noopener">Pobierz etykietę</a></div>
                        <?php endif; ?>
                        <?php if (!empty($lab['error'])): ?>
                            <div class="mt-1 text-rose-700">Błąd: <?= e((string)$lab['error']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>

                <?php if ($canEdit): ?>
                    <form class="mt-2" method="post" action="/admin/shipping/api/create_label.php">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                        <button class="px-3 py-2 rounded-lg bg-stone-900 text-white hover:bg-stone-800">➕ Utwórz etykietę</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($active === 'logs'): ?>
        <div class="rounded-xl border border-stone-200 overflow-hidden">
            <div class="px-4 py-3 border-b bg-stone-50 font-semibold">Logi powiązane</div>
            <div class="p-4 text-sm">
                <iframe class="w-full h-[60vh] rounded-lg border"
                    src="/admin/logs/index.php?order_id=<?= (int)$order['id'] ?>"></iframe>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php if (isset($_GET['msg']) && $_GET['msg'] === 'status_changed'): ?>
    <div class="mb-3 px-3 py-2 rounded-lg border border-green-200 bg-green-50 text-green-800 text-sm">
        ✅ Zmieniono status: <b><?= e((string)($_GET['from'] ?? '')) ?></b> → <b><?= e((string)($_GET['to'] ?? '')) ?></b>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
