<?php
// admin/products/waitlist.php – Lista rezerwacji/oczekujących klientów dla produktu (Baza V2, zgodna z kolumnami stock_* i source_type)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/top_panel.php';
require_once __DIR__ . '/../../layout/layout_header.php';

$owner_id   = (int)($_SESSION['user']['owner_id'] ?? 0);
$product_id = (int)($_GET['product_id'] ?? 0);

// parametry widoku
$limitWh = ['25', '50', '100'];
$limit   = (int)(in_array(($_GET['limit'] ?? ''), $limitWh, true) ? $_GET['limit'] : 50);
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $limit;

$status  = $_GET['status'] ?? 'reserved';    // reserved|released|committed|all
$source  = $_GET['source'] ?? '';            // live|manual|''  (schema: source_type)

if ($product_id <= 0) {
  echo "<div class='max-w-4xl mx-auto px-4 py-6'><p class='text-red-600 text-lg font-semibold'>❌ Brak ID produktu.</p></div>";
  require_once __DIR__ . '/../../layout/layout_footer.php';
  exit;
}

// Produkt + stany (używamy kolumn: stock_cached, stock_reserved_cached, stock_available)
// Dodatkowo liczymy reserved_qty z tabeli rezerwacji (status='reserved') w ramach ownera – dla sanity check.
$q = $pdo->prepare("
  SELECT
    p.*,
    p.stock_cached,
    p.stock_reserved_cached,
    p.stock_available,
    COALESCE((
      SELECT SUM(sr2.qty)
      FROM stock_reservations sr2
      WHERE sr2.product_id = p.id
        AND sr2.owner_id   = p.owner_id
        AND sr2.status     = 'reserved'
    ), 0) AS reserved_qty_calc
  FROM products p
  WHERE p.id = :pid AND p.owner_id = :oid
  LIMIT 1
");
$q->execute([':pid' => $product_id, ':oid' => $owner_id]);
$product = $q->fetch(PDO::FETCH_ASSOC);

if (!$product) {
  echo "<div class='max-w-4xl mx-auto px-4 py-6'><p class='text-red-600 text-lg font-semibold'>❌ Produkt nie istnieje albo nie należy do tego właściciela.</p></div>";
  require_once __DIR__ . '/../../layout/layout_footer.php';
  exit;
}

// Filtry rezerwacji (schema: stock_reservations.product_id, owner_id, status, source_type)
$wf  = ["sr.product_id = :pid", "sr.owner_id = :oid"];
$par = [':pid' => $product_id, ':oid' => $owner_id];

if ($status && $status !== 'all') {
  $wf[] = "sr.status = :status";
  $par[':status'] = $status;
}

// Mapowanie legacy wartości źródła (gdyby przyszły inne) → schema live/manual
$source = trim((string)$source);
if ($source !== '') {
  $allowedSources = ['live', 'manual'];
  if (in_array($source, $allowedSources, true)) {
    $wf[] = "sr.source_type = :source_type";
    $par[':source_type'] = $source;
  }
}

$where = implode(' AND ', $wf);

// Licznik do paginacji
$count = $pdo->prepare("SELECT COUNT(*) FROM stock_reservations sr WHERE $where");
$count->execute($par);
$total_rows  = (int)$count->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / max(1, $limit)));
if ($page > $total_pages) {
  $page = $total_pages;
  $offset = ($page - 1) * $limit;
}

// Lista rezerwacji
$sql = "
  SELECT
    sr.id,
    sr.qty,
    sr.status,
    sr.source_type,
    sr.live_id,
    sr.client_id,
    sr.created_at,
    sr.updated_at,
    c.name         AS client_name,
    c.client_token AS client_token
  FROM stock_reservations sr
  LEFT JOIN clients c ON c.id = sr.client_id
  WHERE $where
  ORDER BY sr.created_at DESC
  LIMIT :limit OFFSET :offset
";
$st = $pdo->prepare($sql);
foreach ($par as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':limit', $limit, PDO::PARAM_INT);
$st->bindValue(':offset', $offset, PDO::PARAM_INT);
$st->execute();
$reservations = $st->fetchAll(PDO::FETCH_ASSOC);

// Klasa statusu → kolor
function statusBadge($s)
{
  return match ($s) {
    'reserved'  => 'bg-yellow-100 text-yellow-800',
    'committed' => 'bg-emerald-100 text-emerald-800',
    'released'  => 'bg-gray-100 text-gray-700',
    default     => 'bg-gray-100 text-gray-700',
  };
}

// helper do budowania query string z zachowaniem filtrów
function buildQuery(array $extra = []): string
{
  $base = $_GET;
  foreach ($extra as $k => $v) {
    if ($v === null) unset($base[$k]);
    else $base[$k] = $v;
  }
  return http_build_query($base);
}

// Bezpieczne wyciągnięcie liczb (decimal → int do wyświetlenia sztuk)
$stock_cached          = (float)($product['stock_cached'] ?? 0);
$stock_reserved_cached = (float)($product['stock_reserved_cached'] ?? 0);
$stock_available       = (float)($product['stock_available'] ?? 0);
$reserved_calc         = (float)($product['reserved_qty_calc'] ?? 0);
?>
<div class="max-w-5xl mx-auto px-4 py-6">
  <div class="flex items-start justify-between gap-4 mb-4">
    <h1 class="text-2xl font-bold">
      🧍 Lista rezerwacji — <span class="text-indigo-600"><?= htmlspecialchars($product['name'] ?? '') ?></span>
    </h1>
    <div class="text-sm text-gray-600">
      Stan (cache): <span class="font-semibold"><?= rtrim(rtrim(number_format($stock_cached, 3, '.', ''), '0'), '.') ?></span>,
      Zarezerwowane (cache): <span class="font-semibold"><?= rtrim(rtrim(number_format($stock_reserved_cached, 3, '.', ''), '0'), '.') ?></span>,
      Wolny (gen): <span class="font-semibold <?= ($stock_available > 0 ? 'text-emerald-600' : 'text-red-600') ?>">
        <?= rtrim(rtrim(number_format($stock_available, 3, '.', ''), '0'), '.') ?>
      </span>
      <div class="text-xs text-gray-400">
        (Rezerwacje zliczone z tabeli: <?= rtrim(rtrim(number_format($reserved_calc, 3, '.', ''), '0'), '.') ?>)
      </div>
    </div>
  </div>

  <form class="flex flex-wrap items-center gap-2 mb-4" method="get">
    <input type="hidden" name="product_id" value="<?= (int)$product_id ?>">
    <select name="status" class="border rounded p-1">
      <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Wszystkie statusy</option>
      <option value="reserved" <?= $status === 'reserved' ? 'selected' : '' ?>>reserved</option>
      <option value="committed" <?= $status === 'committed' ? 'selected' : '' ?>>committed</option>
      <option value="released" <?= $status === 'released' ? 'selected' : '' ?>>released</option>
    </select>
    <select name="source" class="border rounded p-1" title="Źródło rezerwacji (schema: source_type)">
      <option value="" <?= $source === '' ? 'selected' : '' ?>>Wszystkie źródła</option>
      <option value="live" <?= $source === 'live' ? 'selected' : '' ?>>LIVE</option>
      <option value="manual" <?= $source === 'manual' ? 'selected' : '' ?>>Manual</option>
    </select>
    <select name="limit" class="border rounded p-1" onchange="this.form.submit()">
      <option value="25" <?= $limit == 25  ? 'selected' : '' ?>>25</option>
      <option value="50" <?= $limit == 50  ? 'selected' : '' ?>>50</option>
      <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
    </select>
    <button class="px-3 py-1 bg-blue-600 text-white rounded">Filtruj</button>
    <a href="/admin/products/index.php" class="text-gray-500 hover:underline ml-auto">← Powrót do listy</a>
  </form>

  <?php if (!$reservations): ?>
    <div class="bg-white p-4 rounded shadow text-gray-500">
      Brak rezerwacji spełniających filtr dla tego produktu.
    </div>
  <?php else: ?>
    <div class="bg-white shadow rounded overflow-hidden">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 border-b">
          <tr>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Klient</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Token</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ilość</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Źródło</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Utworzono</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Akcja</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <?php foreach ($reservations as $r): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2 font-medium text-gray-800">
                <?= htmlspecialchars($r['client_name'] ?? '—') ?>
                <?php if (!empty($r['client_id'])): ?>
                  <a class="ml-2 text-xs text-gray-500 hover:underline" href="/admin/clients/view.php?id=<?= (int)$r['client_id'] ?>">(podejrzyj)</a>
                <?php endif; ?>
              </td>
              <td class="px-4 py-2 font-mono text-xs text-gray-600"><?= htmlspecialchars($r['client_token'] ?? '—') ?></td>
              <td class="px-4 py-2"><?= rtrim(rtrim(number_format((float)$r['qty'], 3, '.', ''), '0'), '.') ?></td>
              <td class="px-4 py-2">
                <span class="px-2 py-1 rounded text-xs bg-indigo-100 text-indigo-800">
                  <?= htmlspecialchars($r['source_type'] ?? '-') ?>
                  <?php if (!empty($r['live_id'])): ?>
                    <span class="ml-1 text-[10px] opacity-70">#<?= (int)$r['live_id'] ?></span>
                  <?php endif; ?>
                </span>
              </td>
              <td class="px-4 py-2">
                <span class="px-2 py-1 rounded text-xs <?= statusBadge($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span>
              </td>
              <td class="px-4 py-2 text-gray-500"><?= $r['created_at'] ? date('Y-m-d H:i', strtotime($r['created_at'])) : '—' ?></td>
              <td class="px-4 py-2">
                <div class="flex items-center gap-3">
                  <form action="notify_client.php" method="post">
                    <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="text-blue-600 hover:underline">✉️ Powiadom</button>
                  </form>
                  <?php if ($r['status'] === 'reserved'): ?>
                    <form action="reservation_release.php" method="post" onsubmit="return confirm('Zwolnić rezerwację?');">
                      <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="text-red-600 hover:underline">🗑️ Zwolnij</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="mt-4 flex gap-2 flex-wrap">
      <?php for ($i = 1; $i <= max(1, $total_pages); $i++): ?>
        <a href="?<?= buildQuery(['page' => $i]) ?>"
          class="px-2 py-1 border rounded <?= $i == $page ? 'bg-blue-600 text-white' : 'text-gray-700' ?>">
          <?= $i ?>
        </a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php';
