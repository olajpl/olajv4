<?php
// admin/orders/index.php — Lista zamówień (Olaj.pl V4, engine-first)
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';

require_once __DIR__ . '/../../layout/layout_header.php';

// ==== ENGINEs / ENUMs ====
require_once __DIR__ . '/../../../engine/Orders/ViewRenderer.php';
require_once __DIR__ . '/../../../engine/Enum/OrderStatus.php';

use Engine\Orders\ViewRenderer;
use Engine\Enum\OrderStatus;

if (session_status() === PHP_SESSION_NONE) session_start();
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($ownerId <= 0) {
  http_response_code(403);
  exit('❌ Brak owner_id w sesji.');
}

// ───────────────────────────────────────────────────────────────
// Wejście + defaults
// ───────────────────────────────────────────────────────────────
$q          = trim((string)($_GET['q'] ?? ''));
$statuses   = (array)($_GET['status'] ?? []);             // np. ['nowe','gotowe_do_wysyłki']
$paid       = trim((string)($_GET['paid_status'] ?? '')); // 'nieopłacona' / 'częściowa' / 'opłacona' / 'nadpłata'
$dateFrom   = trim((string)($_GET['from'] ?? ''));        // YYYY-MM-DD
$dateTo     = trim((string)($_GET['to'] ?? ''));          // YYYY-MM-DD
$limit      = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$page       = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($page - 1) * $limit;
$debug      = isset($_GET['debug']) && $_GET['debug'] !== '0';

// Aliasy PL/EN dla open_package
$STATUS_ALIASES = [
  'otwarta_paczka:add_products' => ['otwarta_paczka:add_products', 'open_package:add_products'],
  'open_package:add_products'   => ['otwarta_paczka:add_products', 'open_package:add_products'],
  'otwarta_paczka:payment_only' => ['otwarta_paczka:payment_only', 'open_package:payment_only'],
  'open_package:payment_only'   => ['otwarta_paczka:payment_only', 'open_package:payment_only'],
];

// Rozszerz wybór o aliasy i zduplikaty wywal
if ($statuses) {
  $expanded = [];
  foreach ($statuses as $s) {
    if (isset($STATUS_ALIASES[$s])) {
      foreach ($STATUS_ALIASES[$s] as $alt) $expanded[$alt] = true;
    } else {
      $expanded[$s] = true;
    }
  }
  $statuses = array_keys($expanded);
}

// ───────────────────────────────────────────────────────────────
// Helper: IN(...) z nazwanymi placeholderami
// ───────────────────────────────────────────────────────────────
$bindIn = function (string $prefix, array $values, array &$params): string {
  $ph = [];
  $i  = 0;
  foreach ($values as $v) {
    $key = sprintf('%s_%d', $prefix, $i++);
    $ph[] = ':' . $key;
    $params[$key] = $v;
  }
  return implode(',', $ph);
};

// ───────────────────────────────────────────────────────────────
// WHERE do COUNT (możemy użyć JOIN og w countSQL)
// ───────────────────────────────────────────────────────────────
$whereCount  = [];
$paramsCount = [];

$whereCount[] = 'o.owner_id = :owner_id';
$paramsCount['owner_id'] = $ownerId;

$qHas = ($q !== '');
if ($qHas) {
  $whereCount[] = '(o.id = :q_id OR c.name LIKE :q_like OR c.email LIKE :q_like OR og.checkout_token = :q_token)';
  $paramsCount['q_id']    = ctype_digit($q) ? (int)$q : -1;
  $paramsCount['q_like']  = '%' . $q . '%';
  $paramsCount['q_token'] = $q;
}

if ($statuses) {
  $in = $bindIn('st', $statuses, $paramsCount);
  $whereCount[] = "o.order_status IN ($in)";
}

$paidHas = ($paid !== '');
if ($paidHas) {
  $whereCount[] = 'og.paid_status = :paid_status';
  $paramsCount['paid_status'] = $paid;
}

if ($dateFrom !== '') {
  $whereCount[] = 'o.created_at >= :from_dt';
  $paramsCount['from_dt'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
  $whereCount[] = 'o.created_at <= :to_dt';
  $paramsCount['to_dt'] = $dateTo . ' 23:59:59';
}

$whereCountSQL = $whereCount ? ('WHERE ' . implode(' AND ', $whereCount)) : '';

// ───────────────────────────────────────────────────────────────
// COUNT do paginacji
// ───────────────────────────────────────────────────────────────
$countSQL = "
SELECT COUNT(DISTINCT o.id) AS total
FROM orders o
LEFT JOIN order_groups og ON og.order_id = o.id
LEFT JOIN clients c ON c.id = o.client_id
{$whereCountSQL}
";
$countStmt = $pdo->prepare($countSQL);
$countStmt->execute($paramsCount);
$total = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
$pages = max(1, (int)ceil($total / $limit));

// ───────────────────────────────────────────────────────────────
// WHERE do listy (EXISTS zamiast fan-outu na og)
// ───────────────────────────────────────────────────────────────
$whereList  = [];
$paramsList = [];

$whereList[] = 'o.owner_id = :owner_id';
$paramsList['owner_id'] = $ownerId;

if ($qHas) {
  $whereList[] = '(
      o.id = :q_id
      OR c.name LIKE :q_like
      OR c.email LIKE :q_like
      OR EXISTS (SELECT 1 FROM order_groups og WHERE og.order_id = o.id AND og.checkout_token = :q_token)
  )';
  $paramsList['q_id']    = ctype_digit($q) ? (int)$q : -1;
  $paramsList['q_like']  = '%' . $q . '%';
  $paramsList['q_token'] = $q;
}

if ($statuses) {
  $in = $bindIn('st2', $statuses, $paramsList);
  $whereList[] = "o.order_status IN ($in)";
}

if ($paidHas) {
  $whereList[] = "EXISTS (SELECT 1 FROM order_groups og WHERE og.order_id = o.id AND og.paid_status = :paid_status)";
  $paramsList['paid_status'] = $paid;
}

if ($dateFrom !== '') {
  $whereList[] = 'o.created_at >= :from_dt';
  $paramsList['from_dt'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
  $whereList[] = 'o.created_at <= :to_dt';
  $paramsList['to_dt'] = $dateTo . ' 23:59:59';
}

$whereListSQL = $whereList ? ('WHERE ' . implode(' AND ', $whereList)) : '';

// ───────────────────────────────────────────────────────────────
// Lista — agregaty per order_id + ostatnia płatność (MySQL 8 window)
// ───────────────────────────────────────────────────────────────
$listSQL = "
SELECT
  o.id,
  o.owner_id,
  o.client_id,
  o.order_status,
  o.created_at,
  o.updated_at,
  c.name  AS client_name,
  c.email AS client_email,

  og_agg.groups_count,
  og_agg.completed_groups,
  COALESCE(items_agg.items_value, 0.00) AS items_value,
  og_agg.paid_status_agg AS paid_status_agg,

  pay_agg.pay_status,
  pay_agg.pay_amount,
  pay_agg.pay_created_at,
  pay_agg.pay_method

FROM orders o
LEFT JOIN clients c ON c.id = o.client_id

/* agregacja po grupach (bez fan-out do items) */
LEFT JOIN (
  SELECT
    og.order_id,
    COUNT(*) AS groups_count,
    SUM(CASE WHEN og.checkout_completed = 1 THEN 1 ELSE 0 END) AS completed_groups,
    CASE
      WHEN SUM(og.paid_status = 'nadpłata')  > 0 THEN 'nadpłata'
      WHEN SUM(og.paid_status = 'opłacona')  > 0 THEN 'opłacona'
      WHEN SUM(og.paid_status = 'częściowa') > 0 THEN 'częściowa'
      ELSE 'nieopłacona'
    END AS paid_status_agg
  FROM order_groups og
  GROUP BY og.order_id
) AS og_agg ON og_agg.order_id = o.id

/* suma pozycji po order_id (przez group -> items) */
LEFT JOIN (
  SELECT
    og.order_id,
    SUM(oi.qty * oi.unit_price) AS items_value
  FROM order_groups og
  JOIN order_items oi ON oi.order_group_id = og.id
  GROUP BY og.order_id
) AS items_agg ON items_agg.order_id = o.id

/* ostatnia płatność (status != 'draft') po wszystkich grupach zamówienia */
LEFT JOIN (
  SELECT
    x.order_id,
    x.pay_status,
    x.pay_amount,
    x.pay_created_at,
    x.pay_method
  FROM (
    SELECT
      og.order_id,
      p.status       AS pay_status,
      p.amount       AS pay_amount,
      p.created_at   AS pay_created_at,
      pm.name        AS pay_method,
      ROW_NUMBER() OVER (PARTITION BY og.order_id ORDER BY p.created_at DESC, p.id DESC) AS rn
    FROM order_groups og
    JOIN payments p              ON p.order_group_id = og.id
    LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
    WHERE p.status <> 'draft'
  ) x
  WHERE x.rn = 1
) AS pay_agg ON pay_agg.order_id = o.id

{$whereListSQL}
GROUP BY o.id
ORDER BY o.created_at DESC
LIMIT {$limit} OFFSET {$offset}
";

$listStmt = $pdo->prepare($listSQL);
$listStmt->execute($paramsList);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Debug quick
if ($debug) {
  echo '<pre style="max-height:40vh;overflow:auto;background:#111;color:#0f0;padding:8px">';
  echo "WHERE (count): " . htmlspecialchars($whereCountSQL) . "\n";
  echo "PARAMS (count):\n";
  var_export($paramsCount);
  echo "\n\nWHERE (list): " . htmlspecialchars($whereListSQL) . "\n";
  echo "PARAMS (list):\n";
  var_export($paramsList);
  echo "\n\nSQL (list):\n$listSQL\n";
  echo "</pre>";
}

// Log diagnostyczny (nie spamujmy — lekki level)
try {
  logg('debug', 'admin.orders.index', 'orders:list', [
    'owner_id' => $ownerId,
    'q' => $q,
    'statuses' => $statuses,
    'paid_status' => $paid,
    'from' => $dateFrom,
    'to' => $dateTo,
    'limit' => $limit,
    'page'  => $page,
    'total' => $total,
  ]);
} catch (Throwable $__) {
}

?>
<div class="p-4 md:p-6">
  <div class="flex items-center justify-between mb-4">
    <h1 class="text-lg md:text-2xl font-semibold">Zamówienia</h1>
    <a href="/admin/orders/create.php" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-stone-900 text-white hover:bg-stone-800">
      ➕ Nowe zamówienie
    </a>
  </div>

  <?php
  // Pasek szybkich przycisków statusów
  $statusButtons = [
    'Wszystkie'          => [],
    'Nowe'               => [OrderStatus::NEW->value],
    'Dodawanie produktów' => [OrderStatus::OPEN_PACKAGE_ADD_PRODUCTS->value],
    'Payment only'       => [OrderStatus::OPEN_PACKAGE_PAYMENT_ONLY->value],
    'Czeka na płatność'  => [OrderStatus::AWAITING_PAYMENT->value],
    'Do wysyłki'         => [OrderStatus::READY_TO_SHIP->value],
    'Wysłane'            => [OrderStatus::SHIPPED->value],
    'Zrealizowane'       => [OrderStatus::COMPLETED->value],
    'Zarchiwizowane'     => [OrderStatus::ARCHIVED->value],
    'Anulowane'          => [OrderStatus::CANCELLED->value],
  ];
  $qsBase = $_GET;
  unset($qsBase['status'], $qsBase['page']);
  $baseURL = '/admin/orders/index.php?' . http_build_query($qsBase);
  ?>
  <div class="mb-4 flex flex-wrap gap-2">
    <?php foreach ($statusButtons as $label => $vals):
      if ($label === 'Wszystkie') {
        $activeAll = empty($statuses);
    ?>
        <a href="<?= $baseURL ?>"
          class="px-3 py-1.5 rounded-lg border <?= $activeAll ? 'bg-stone-900 text-white' : 'border-stone-300 hover:bg-stone-100' ?>">
          Wszystkie
        </a>
      <?php
        continue;
      }
      // Link z doklejeniem wielu status[] (w tym aliasów)
      $qsSt = $qsBase;
      foreach ($vals as $v) $qsSt['status'][] = $v;
      $href = '/admin/orders/index.php?' . http_build_query($qsSt);

      // Aktywny, jeśli KOLIZJA zbiorów (cokolwiek z przycisku jest w wybranych)
      $active = (bool)array_intersect($vals, $statuses);
      ?>
      <a href="<?= $href ?>"
        class="px-3 py-1.5 rounded-lg border <?= $active ? 'bg-stone-900 text-white' : 'border-stone-300 hover:bg-stone-100' ?>">
        <?= htmlspecialchars($label) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Formularz pozostałych filtrów -->
  <form method="get" class="grid grid-cols-1 md:grid-cols-7 gap-3 mb-4">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Szukaj: #ID, klient, e-mail, token"
      class="md:col-span-2 w-full px-3 py-2 rounded-lg border border-stone-300" />

    <select name="paid_status" class="w-full px-3 py-2 rounded-lg border border-stone-300">
      <option value="">Płatność (dowolna)</option>
      <?php foreach (['nieopłacona', 'częściowa', 'opłacona', 'nadpłata'] as $opt): ?>
        <option value="<?= htmlspecialchars($opt) ?>" <?= $paid === $opt ? 'selected' : '' ?>>
          <?= htmlspecialchars($opt) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" class="w-full px-3 py-2 rounded-lg border border-stone-300" />
    <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" class="w-full px-3 py-2 rounded-lg border border-stone-300" />

    <select name="limit" class="w-full px-3 py-2 rounded-lg border border-stone-300">
      <?php foreach ([25, 50, 100, 200] as $l): ?>
        <option value="<?= $l ?>" <?= $limit === $l ? 'selected' : '' ?>><?= $l ?>/stronę</option>
      <?php endforeach; ?>
    </select>

    <div class="md:col-span-7 flex items-center gap-2">
      <button class="px-4 py-2 rounded-lg bg-stone-900 text-white hover:bg-stone-800">Filtruj</button>
      <a href="/admin/orders/index.php" class="px-3 py-2 rounded-lg border border-stone-300 hover:bg-stone-50">Wyczyść</a>
      <label class="ml-auto inline-flex items-center gap-2 text-sm">
        <input type="checkbox" name="debug" value="1" <?= $debug ? 'checked' : '' ?> onchange="this.form.submit()">
        Debug
      </label>
      <?php
      // Zachowaj statusy (hidden), by nie zniknęły przy innych filtrach:
      foreach ($statuses as $st) {
        echo '<input type="hidden" name="status[]" value="' . htmlspecialchars($st) . '">';
      }
      ?>
    </div>
  </form>

  <div class="rounded-xl border border-stone-200 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-stone-50">
        <tr>
          <th class="text-left px-3 py-2">ID</th>
          <th class="text-left px-3 py-2">Klient</th>
          <th class="text-left px-3 py-2">Status</th>
          <th class="text-right px-3 py-2">Wartość pozycji</th>
          <th class="text-center px-3 py-2">Grupy</th>
          <th class="text-left px-3 py-2">Płatność (agregat)</th>
          <th class="text-left px-3 py-2">Ostatnia płatność</th>
          <th class="text-left px-3 py-2">Utworzone</th>
          <th class="px-3 py-2"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="9" class="px-3 py-6 text-center text-stone-500">Brak wyników.</td>
          </tr>
          <?php else: foreach ($rows as $r): ?>
            <tr class="border-t border-stone-200 hover:bg-stone-50">
              <td class="px-3 py-2 font-medium">#<?= (int)$r['id'] ?></td>
              <td class="px-3 py-2">
                <div class="font-medium"><?= htmlspecialchars($r['client_name'] ?? '—') ?></div>
                <div class="text-stone-500"><?= htmlspecialchars($r['client_email'] ?? '') ?></div>
              </td>
              <td class="px-3 py-2">
                <?php
                // Badge statusu przez ViewRenderer + OrderStatus enum
                $statusRaw = (string)($r['order_status'] ?? '');
                echo ViewRenderer::renderStatusBadge($statusRaw);
                ?>
              </td>
              <td class="px-3 py-2 text-right"><?= number_format((float)($r['items_value'] ?? 0), 2, ',', ' ') ?> zł</td>
              <td class="px-3 py-2 text-center">
                <span class="inline-flex items-center gap-1">
                  <b><?= (int)($r['groups_count'] ?? 0) ?></b>
                  <span class="text-stone-500">/</span>
                  <span class="text-green-700"><?= (int)($r['completed_groups'] ?? 0) ?></span>
                </span>
              </td>
              <td class="px-3 py-2">
                <?php
                $agg = (string)($r['paid_status_agg'] ?? 'nieopłacona');
                // prosta wizualizacja
                $paidCls = 'bg-stone-100 border-stone-200';
                if ($agg === 'opłacona' || $agg === 'nadpłata') $paidCls = 'bg-green-100 border-green-200';
                elseif ($agg === 'częściowa') $paidCls = 'bg-amber-100 border-amber-200';
                echo '<span class="inline-flex items-center px-2 py-0.5 rounded-full border ' . $paidCls . '">' . htmlspecialchars($agg) . '</span>';
                ?>
              </td>
              <td class="px-3 py-2">
                <?php
                $lpStatus = $r['pay_status'] ?? null;
                $lpAmt    = $r['pay_amount'] ?? null;
                $lpMeth   = $r['pay_method'] ?? null;
                $lpWhen   = $r['pay_created_at'] ?? null;

                if ($lpStatus) {
                  $badgeClass = 'bg-stone-100 border-stone-200';
                  if ($lpStatus === 'paid') $badgeClass = 'bg-green-100 border-green-200';
                  elseif ($lpStatus === 'pending' || $lpStatus === 'started') $badgeClass = 'bg-amber-100 border-amber-200';
                  elseif ($lpStatus === 'failed' || $lpStatus === 'cancelled') $badgeClass = 'bg-red-100 border-red-200';
                ?>
                  <div class="flex flex-col gap-1">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full border <?= $badgeClass ?>">
                      <?= htmlspecialchars((string)$lpStatus) ?>
                      <?php if ($lpMeth): ?>
                        <span class="ml-2 text-stone-600">· <?= htmlspecialchars((string)$lpMeth) ?></span>
                      <?php endif; ?>
                    </span>
                    <div class="text-stone-600 text-xs">
                      <?php if ($lpAmt !== null): ?>
                        Kwota: <b><?= number_format((float)$lpAmt, 2, ',', ' ') ?> zł</b>
                      <?php endif; ?>
                      <?php if ($lpWhen): ?>
                        <span class="ml-2"><?= htmlspecialchars((string)$lpWhen) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php
                } else {
                  echo '<span class="text-stone-500">—</span>';
                }
                ?>
              </td>
              <td class="px-3 py-2"><?= htmlspecialchars((string)$r['created_at']) ?></td>
              <td class="px-3 py-2 text-right">
                <a class="px-3 py-1.5 rounded-lg border border-stone-300 hover:bg-stone-100" href="/admin/orders/view.php?id=<?= (int)$r['id'] ?>">Podgląd</a>
              </td>
            </tr>
        <?php endforeach;
        endif; ?>
      </tbody>
    </table>
  </div>

  <div class="mt-4 flex items-center justify-between text-sm">
    <div class="text-stone-600">
      Łącznie: <b><?= $total ?></b>, strona <b><?= $page ?></b>/<b><?= $pages ?></b>
    </div>
    <div class="flex items-center gap-2">
      <?php
      $qsPag = $_GET;
      unset($qsPag['page']);
      $basePag = '/admin/orders/index.php?' . http_build_query($qsPag);
      ?>
      <a class="px-3 py-1.5 rounded border <?= $page <= 1 ? 'opacity-50 pointer-events-none' : 'hover:bg-stone-100 border-stone-300' ?>"
        href="<?= $basePag . '&page=' . max(1, $page - 1) ?>">← Poprzednia</a>
      <a class="px-3 py-1.5 rounded border <?= $page >= $pages ? 'opacity-50 pointer-events-none' : 'hover:bg-stone-100 border-stone-300' ?>"
        href="<?= $basePag . '&page=' . min($pages, $page + 1) ?>">Następna →</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>