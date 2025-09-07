<?php
// admin/orders/view.php — szczegóły zamówienia (Olaj.pl V4) — one-page + historia pod płatnościami
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';

use Engine\Orders\ViewRenderer;
use Engine\Enum\OrderStatus;

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

// Helpery
function e(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function zl(float $v): string { return number_format($v, 2, ',', ' ') . ' zł'; }
function dt(?string $s): string { if (!$s) return '—'; $t = strtotime($s); return $t ? date('Y-m-d H:i', $t) : e($s); }

// Dane z engine
$data = ViewRenderer::loadOrderData($pdo, $orderId, $ownerId);
if (!$data) { http_response_code(404); exit('❌ Zamówienie nie istnieje lub brak dostępu.'); }

$order        = is_array($data['order'] ?? null) ? $data['order'] : [];
$client       = is_array($data['client'] ?? null) ? $data['client'] : [];
$groups       = is_array($data['groups'] ?? null) ? $data['groups'] : [];
$itemsByGroup = is_array($data['itemsByGroup'] ?? null) ? $data['itemsByGroup'] : [];
$dues         = is_array($data['dues'] ?? null) ? $data['dues'] : [];
$paid         = is_array($data['paid'] ?? null) ? $data['paid'] : [];
$applied      = is_array($data['appliedPaid'] ?? null) ? $data['appliedPaid'] : [];
$firstAddress = is_array($data['firstAddress'] ?? null) ? $data['firstAddress'] : null;
$shippingName = $data['shippingName'] ?? null;
$canEdit      = !empty($order) && (int)($order['checkout_completed'] ?? 0) !== 1;

// Koszty wysyłki
$shippingCostByGroup = is_array($data['shippingCostByGroup'] ?? null) ? $data['shippingCostByGroup'] : [];
$totalShipping = isset($data['shippingCostTotal'])
    ? (float)$data['shippingCostTotal']
    : array_reduce($groups, function (float $acc, array $g) use ($shippingCostByGroup): float {
        $gid = (int)($g['id'] ?? 0);
        return $acc + (float)($shippingCostByGroup[$gid] ?? 0.0);
    }, 0.0);

// Agregaty płatności
$totalDue  = 0.0;
$totalPaid = 0.0;
$eps = 0.01;
foreach ($groups as $g) {
    $gid = (int)($g['id'] ?? 0);
    $totalDue  += (float)($dues[$gid] ?? 0.0);
    $totalPaid += (float)($paid[$gid] ?? 0.0);
}
$totalDueWithShip = $totalDue + $totalShipping;
$balance          = $totalDueWithShip - $totalPaid;
$aggPayStatus = ($totalPaid <= $eps)
    ? 'nieopłacona'
    : (($totalPaid + $eps < $totalDueWithShip)
        ? 'częściowa'
        : ((abs($totalPaid - $totalDueWithShip) <= $eps) ? 'opłacona' : 'nadpłata'));

// Waga
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
// Head
// ───────────────────────────────────────────────────────────────
?>
<div class="p-4 md:p-6">
  <div class="flex items-start justify-between gap-3 mb-5">
    <div class="min-w-0">
      <div class="flex items-center gap-3 flex-wrap">
        <h1 class="text-xl md:text-2xl font-semibold">Zamówienie #<?= (int)($order['id'] ?? 0) ?></h1>
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
          <input type="hidden" name="order_id" value="<?= (int)($order['id'] ?? 0) ?>">
          <select name="status" class="px-3 py-2 rounded-lg border border-stone-300" aria-label="Zmień status zamówienia">
            <?php
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

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <!-- Lewa kolumna: przegląd -->
    <div class="lg:col-span-2 space-y-4">
      <?php require __DIR__ . '/partials/client_info.php'; ?>

      <?php
        require __DIR__ . '/partials/_overview_scan.php';
        $canEditLocal = $canEdit;
        require __DIR__ . '/partials/_overview_groups.php';
        require __DIR__ . '/partials/_overview_groups_js.php';
      ?>

      <!-- Etykiety i paczki (przeniesione z dawnej zakładki) -->
      <div class="rounded-xl border border-stone-200 overflow-hidden">
        <div class="px-4 py-3 border-b bg-stone-50 font-semibold">Etykiety i paczki</div>
        <div class="p-4 text-sm space-y-3">
          <?php
          $labels = [];
          try {
              $stmtL = $pdo->prepare("SELECT * FROM shipping_labels WHERE order_id = ? ORDER BY id DESC");
              $stmtL->execute([$orderId]);
              $labels = $stmtL->fetchAll(PDO::FETCH_ASSOC) ?: [];
          } catch (Throwable $__) {}
          ?>
          <?php if (!$labels): ?>
              <div class="text-stone-500">Brak wygenerowanych etykiet.</div>
          <?php else: foreach ($labels as $lab): ?>
              <div class="p-3 border rounded-lg">
                  <div class="flex items-center justify-between">
                      <div class="font-medium">
                          #<?= (int)($lab['id'] ?? 0) ?> • <?= e((string)($lab['carrier'] ?? '')) ?> • <?= e((string)($lab['status'] ?? 'pending')) ?>
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
                  <input type="hidden" name="order_id" value="<?= (int)($order['id'] ?? 0) ?>">
                  <button class="px-3 py-2 rounded-lg bg-stone-900 text-white hover:bg-stone-800">➕ Utwórz etykietę</button>
              </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Prawa kolumna: podsumowanie + płatności + HISTORIA -->
    <div class="space-y-4">
      <div class="rounded-xl border border-stone-200">
        <div class="px-4 py-3 border-b bg-stone-50 font-semibold">Szybkie podsumowanie</div>
        <div class="p-4 text-sm space-y-1">
          <div class="flex justify-between"><span>Wartość pozycji:</span><b><?= zl($totalDue) ?></b></div>
          <?php
            $shipKey = (string)($order['shipping_paid_status_key'] ?? 'unpaid');
            $shipAt  = (string)($order['shipping_paid_at'] ?? '');
          ?>
          <div class="flex justify-between items-center gap-2">
            <span>Koszt wysyłki:</span>
            <div class="flex items-center gap-2">
              <span id="shipPaidBadgeInline"
                    title="<?= $shipKey==='paid' && $shipAt ? 'Opłacona: '.e($shipAt) : 'Status: '.($shipKey==='paid'?'opłacona':'nieopłacona') ?>"
                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs
                           <?= $shipKey==='paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-700' ?>">
                <?= $shipKey==='paid' ? 'opłacona' : 'nieopłacona' ?>
              </span>
              <b><?= zl($totalShipping) ?></b>
            </div>
          </div>

          <div class="flex justify-between"><span>Suma (pozycje + wysyłka):</span><b><?= zl($totalDueWithShip) ?></b></div>
          <div class="flex justify-between"><span>Zapłacono:</span><b><?= zl($totalPaid) ?></b></div>
          <div class="flex justify-between">
            <span>Do zapłaty (z wysyłką):</span>
            <b class="<?= $balance > $eps ? 'text-rose-700' : 'text-emerald-700' ?>"><?= zl(max($balance, 0.0)) ?></b>
          </div>
          <div class="flex justify-between"><span>Status płatności:</span><b class="lowercase"><?= e($aggPayStatus) ?></b></div>

          <hr class="my-2 border-stone-200">
          <?php $curShipPaid = (string)($order['shipping_paid_status_key'] ?? 'unpaid'); $shipAt = (string)($order['shipping_paid_at'] ?? ''); ?>
          <div class="flex justify-between items-center gap-3">
            <span class="flex items-center gap-2">
              Status opłacenia wysyłki:
              <span id="shipPaidBadge"
                    title="<?= $curShipPaid==='paid' && $shipAt ? 'Opłacona: '.e($shipAt) : 'Status: '.($curShipPaid==='paid'?'opłacona':'nieopłacona') ?>"
                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs
                           <?= $curShipPaid==='paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-100 text-stone-700' ?>">
                <?= $curShipPaid==='paid' ? 'opłacona' : 'nieopłacona' ?>
              </span>
            </span>
            <div class="flex items-center gap-2">
              <select class="px-2 py-1 rounded-lg border border-stone-300" id="shipPaidSelect"
                      data-order-id="<?= (int)($order['id'] ?? 0) ?>" aria-label="Zmień status opłacenia kosztu wysyłki">
                <option value="unpaid" <?= $curShipPaid==='unpaid'?'selected':''; ?>>nieopłacona</option>
                <option value="paid"   <?= $curShipPaid==='paid'  ?'selected':''; ?>>opłacona</option>
              </select>
              <span id="shipPaidSpinner" class="hidden text-stone-400 text-sm">zapisywanie…</span>
              <span id="shipPaidTick" class="hidden text-emerald-600 text-sm">✔</span>
            </div>
          </div>
          <div class="text-xs text-stone-500 mt-1">
            Oznaczono jako opłaconą: <span id="shipPaidAt"><?= e((string)($order['shipping_paid_at'] ?? '—')) ?></span>
          </div>

          <div class="flex justify-between"><span>Grupy:</span><b><?= count($groups) ?></b></div>
          <div class="flex justify-between"><span>Checkout ukończony:</span><b><?= (int)($order['checkout_completed'] ?? 0) ? 'Tak' : 'Nie' ?></b></div>

          <div class="mt-3">
            <?= ViewRenderer::renderPaymentWidget($pdo, $orderId, $ownerId) ?>
          </div>
        </div>
      </div>

      <?php
      // ───── HISTORIA pod płatnościami ─────
      $timeline = [];
      $push = function(string $type, string $title, string $desc = '', ?string $who = null, ?string $ts = null, array $meta = []) use (&$timeline) {
          $timeline[] = compact('type','title','desc','who','ts','meta');
      };

      $push('order.created', 'Utworzono zamówienie', '', 'system', (string)($order['created_at'] ?? null));
      if ((int)($order['checkout_completed'] ?? 0) === 1) {
          $push('order.checkout_completed', 'Checkout zakończony', 'Zamówienie zamrożone do edycji.', 'system', (string)($order['updated_at'] ?? null));
      }
      foreach ($groups as $g) {
          $gid = (int)($g['id'] ?? 0);
          $push('group.created', "Utworzono grupę #$gid", (string)($g['name'] ?? 'Paczka'), 'system', (string)($g['created_at'] ?? null), ['gid'=>$gid]);
      }
      // płatności
      try {
          $groupIds = array_map(fn($g)=> (int)($g['id'] ?? 0), $groups);
          if ($groupIds) {
              $in = implode(',', array_fill(0, count($groupIds), '?'));
              $stmtP = $pdo->prepare("
                  SELECT p.id, p.status, p.amount, p.amount_captured, p.amount_refunded,
                         p.order_group_id, p.order_id,
                         COALESCE(p.updated_at, p.created_at) AS ts
                  FROM payments p
                  WHERE (p.order_group_id IN ($in)) OR (p.order_id = ?)
                  ORDER BY COALESCE(p.updated_at, p.created_at, NOW()) DESC, p.id DESC
              ");
              $stmtP->execute([...$groupIds, $orderId]);
          } else {
              $stmtP = $pdo->prepare("
                  SELECT p.id, p.status, p.amount, p.amount_captured, p.amount_refunded,
                         p.order_group_id, p.order_id,
                         COALESCE(p.updated_at, p.created_at) AS ts
                  FROM payments p
                  WHERE p.order_id = ?
                  ORDER BY COALESCE(p.updated_at, p.created_at, NOW()) DESC, p.id DESC
              ");
              $stmtP->execute([$orderId]);
          }
          foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
              $pid   = (int)($p['id'] ?? 0);
              $gid   = (int)($p['order_group_id'] ?? 0);
              $st    = (string)($p['status'] ?? '');
              $amt   = (float)($p['amount_captured'] ?? $p['amount'] ?? 0.0);
              $ref   = (float)($p['amount_refunded'] ?? 0.0);
              $ts    = (string)($p['ts'] ?? '');
              $label = match ($st) {
                  'paid'      => 'Płatność zaksięgowana',
                  'refunded'  => 'Zwrot środków',
                  'failed'    => 'Płatność nieudana',
                  'cancelled' => 'Płatność anulowana',
                  'pending'   => 'Płatność oczekuje',
                  'started','draft' => 'Płatność rozpoczęta',
                  default     => 'Aktualizacja płatności',
              };
              $desc = 'Grupa: #' . ($gid ?: '—') . ' • Kwota: ' . zl($amt) . ($ref > 0 ? ' • Zwrot: ' . zl($ref) : '');
              $push('payment.'.$st, $label, $desc, null, $ts, ['payment_id'=>$pid, 'group_id'=>$gid]);
          }
      } catch (Throwable $__) {}

      // etykiety
      try {
          $stmtL2 = $pdo->prepare("SELECT id, carrier, status, tracking_number, created_at FROM shipping_labels WHERE order_id = ? ORDER BY id DESC");
          $stmtL2->execute([$orderId]);
          foreach ($stmtL2->fetchAll(PDO::FETCH_ASSOC) ?: [] as $lab) {
              $lid = (int)($lab['id'] ?? 0);
              $car = (string)($lab['carrier'] ?? '');
              $st  = (string)($lab['status'] ?? 'pending');
              $trk = (string)($lab['tracking_number'] ?? '');
              $ts  = (string)($lab['created_at'] ?? '');
              $push('shipping.label', "Etykieta #$lid ($car)", 'Status: '.$st . ($trk ? ' • Tracking: '.e($trk) : ''), 'system', $ts, ['label_id'=>$lid]);
          }
      } catch (Throwable $__) {}

      // centralne order_events (opcjonalnie)
      try {
          $stmtE = $pdo->prepare("
              SELECT type, title, description, actor_name, created_at
              FROM order_events
              WHERE order_id = ?
              ORDER BY created_at DESC
              LIMIT 200
          ");
          $stmtE->execute([$orderId]);
          foreach ($stmtE->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
              $push((string)($row['type'] ?? 'event'),
                   (string)($row['title'] ?? 'Zdarzenie'),
                   (string)($row['description'] ?? ''),
                   (string)($row['actor_name'] ?? null),
                   (string)($row['created_at'] ?? null));
          }
      } catch (Throwable $__) {}

      usort($timeline, function($a, $b){
          $ta = strtotime((string)($a['ts'] ?? '')) ?: 0;
          $tb = strtotime((string)($b['ts'] ?? '')) ?: 0;
          return $tb <=> $ta;
      });
      ?>

      <div class="rounded-xl border border-stone-200 overflow-hidden">
        <div class="px-4 py-3 border-b bg-stone-50 font-semibold">Historia zamówienia</div>
        <div class="p-4">
          <?php if (!$timeline): ?>
            <div class="text-sm text-stone-500">Brak zarejestrowanych zdarzeń.</div>
          <?php else: ?>
            <ol class="relative border-s border-stone-200">
              <?php foreach ($timeline as $ev): ?>
                <?php
                  $when = dt((string)($ev['ts'] ?? null));
                  $who  = trim((string)($ev['who'] ?? ''));
                  $badge = match(true) {
                      str_starts_with($ev['type'], 'payment.')       => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                      str_starts_with($ev['type'], 'shipping.')      => 'bg-indigo-100 text-indigo-700 border-indigo-200',
                      str_starts_with($ev['type'], 'group.')         => 'bg-amber-100 text-amber-700 border-amber-200',
                      str_starts_with($ev['type'], 'order.checkout') => 'bg-stone-200 text-stone-800 border-stone-300',
                      default                                         => 'bg-stone-100 text-stone-700 border-stone-200',
                  };
                ?>
                <li class="ms-6 pb-4 last:pb-0">
                  <span class="absolute -start-1.5 mt-1 flex h-3 w-3 rounded-full bg-stone-300"></span>
                  <div class="flex items-center gap-2 text-xs text-stone-500">
                    <span><?= e($when) ?></span>
                    <?php if ($who !== ''): ?><span>•</span><span><?= e($who) ?></span><?php endif; ?>
                  </div>
                  <div class="mt-0.5 inline-block px-2 py-0.5 rounded border <?= $badge ?>">
                    <?= e((string)$ev['title']) ?>
                  </div>
                  <?php if (!empty($ev['desc'])): ?>
                  <div class="mt-1 text-sm text-stone-700"><?= $ev['desc'] ?></div>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'status_changed'): ?>
  <div class="mb-3 px-3 py-2 rounded-lg border border-green-200 bg-green-50 text-green-800 text-sm">
    ✅ Zmieniono status: <b><?= e((string)($_GET['from'] ?? '')) ?></b> → <b><?= e((string)($_GET['to'] ?? '')) ?></b>
  </div>
<?php endif; ?>

<script>
(function(){
  const sel         = document.getElementById('shipPaidSelect');
  if (!sel) return;

  const badgeMain   = document.getElementById('shipPaidBadge');
  const badgeInline = document.getElementById('shipPaidBadgeInline');
  const spin        = document.getElementById('shipPaidSpinner');
  const tick        = document.getElementById('shipPaidTick');
  const atField     = document.getElementById('shipPaidAt');
  const orderId     = sel.dataset.orderId;

  let inflight = null;
  let debounceTimer = null;

  function applyBadge(el, val, paidAt) {
    if (!el) return;
    const paid = (val === 'paid');
    el.textContent = paid ? 'opłacona' : 'nieopłacona';
    el.className = 'inline-flex items-center px-2 py-0.5 rounded-full text-xs ' + (paid
      ? 'bg-emerald-100 text-emerald-800'
      : 'bg-rose-100 text-rose-700');
    el.title = paid && paidAt ? ('Opłacona: ' + paidAt) : 'Status: ' + (paid ? 'opłacona' : 'nieopłacona');
  }
  function setAll(val, paidAt) {
    applyBadge(badgeMain,   val, paidAt);
    applyBadge(badgeInline, val, paidAt);
    if (atField) atField.textContent = paidAt ? paidAt : '—';
  }
  async function save(val){
    if (inflight) inflight.abort();
    const ctrl = new AbortController();
    inflight = ctrl;
    spin?.classList.remove('hidden');
    tick?.classList.add('hidden');
    sel.disabled = true;
    try {
      const body = new URLSearchParams({ csrf: '<?= e($csrf) ?>', order_id: orderId, value: val });
      const r = await fetch('/admin/orders/api/set_shipping_paid_status.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': '<?= e($csrf) ?>' },
        body,
        signal: ctrl.signal
      });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || 'Błąd zapisu');
      const newVal = j.value || val;
      const paidAt = j.paid_at || null;
      setAll(newVal, paidAt);
      tick?.classList.remove('hidden');
      setTimeout(()=> tick?.classList.add('hidden'), 900);
    } catch (err) {
      alert('Błąd zapisu statusu wysyłki: ' + (err?.message || err));
      const cur = (badgeMain?.textContent?.trim() === 'opłacona') ? 'paid' : 'unpaid';
      sel.value = cur;
    } finally {
      spin?.classList.add('hidden');
      sel.disabled = false;
      inflight = null;
    }
  }
  sel.addEventListener('change', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(()=> save(sel.value), 150);
  });
})();
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
