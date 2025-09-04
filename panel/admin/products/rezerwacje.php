<?php
// admin/products/rezerwacje.php – Zbiorcza lista rezerwacji (KPI + paginacja + akcje) [V4 schema: source_type, owner-scope]
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/top_panel.php';
require_once __DIR__ . '/../../layout/layout_header.php';

// Opcjonalnie podpinamy silnik, jeśli istnieje:
$engineLoaded = false;
$engineReleaseCallable = null;
try {
  $enginePath = __DIR__ . '/../../engine/stock/StockReservationEngine.php';
  if (is_file($enginePath)) {
    require_once $enginePath;
    // Zgadujemy nazwę klasy/namespacu; jeśli trafimy — użyjemy; w przeciwnym razie fallback do SQL.
    $candidates = [
      'Engine\\Stock\\StockReservationEngine',
      'StockReservationEngine',
    ];
    foreach ($candidates as $cn) {
      if (class_exists($cn)) {
        // próbujemy znaleźć sensowną metodę
        foreach (['release', 'releaseReservation', 'releaseById'] as $m) {
          if (method_exists($cn, $m)) {
            $engineLoaded = true;
            $engineReleaseCallable = [$cn, $m];
            break 2;
          }
        }
      }
    }
  }
} catch (Throwable $e) {
  // cicho – mamy fallback SQL
}

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);

// --- POST: akcje na rezerwacjach ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $msg = 'unknown';
  try {
    // Zwolnienie rezerwacji
    if (isset($_POST['release_reservation_id'])) {
      $rid = (int)$_POST['release_reservation_id'];

      // weryfikacja ownera przez produkt
      $chk = $pdo->prepare("
        SELECT 1
        FROM stock_reservations sr
        JOIN products p ON p.id = sr.product_id
        WHERE sr.id = :rid AND p.owner_id = :oid
        LIMIT 1
      ");
      $chk->execute([':rid' => $rid, ':oid' => $owner_id]);
      if ($chk->fetchColumn()) {
        // Spróbuj silnika → fallback SQL
        $ok = false;
        if ($engineLoaded && is_callable($engineReleaseCallable)) {
          try {
            // typowe sygnatury: (PDO, int $ownerId, int $reservationId) lub (PDO, int $reservationId)
            $ref = new ReflectionMethod($engineReleaseCallable[0], $engineReleaseCallable[1]);
            $argc = $ref->getNumberOfParameters();
            if ($argc >= 3) {
              $ok = (bool)($engineReleaseCallable)($pdo, $owner_id, $rid);
            } elseif ($argc === 2) {
              $ok = (bool)($engineReleaseCallable)($pdo, $rid);
            } else {
              $ok = (bool)($engineReleaseCallable)($rid);
            }
          } catch (Throwable $e) {
            $ok = false; // polecimy fallbackiem
          }
        }
        if (!$ok) {
          // Fallback SQL – proste zwolnienie statusu (bez updated_at, bo nie każdy schemat to ma)
          $upd = $pdo->prepare("UPDATE stock_reservations SET status='released', released_at=NOW() WHERE id = :rid LIMIT 1");
          $upd->execute([':rid' => $rid]);
          $ok = true;
        }
        $msg = $ok ? 'released' : 'dberror';
      } else {
        $msg = 'forbidden';
      }
    }
  } catch (Throwable $e) {
    $msg = 'dberror';
  }
  // PRG: redirect z zachowaniem filtrów + flash
  $q = $_GET;
  $q['msg'] = $msg;
  header('Location: /admin/products/rezerwacje.php?' . http_build_query($q));
  exit;
}

// --- Parametry widoku ---
$search     = trim((string)($_GET['q'] ?? ''));               // produkt/klient/kod
$status     = trim((string)($_GET['status'] ?? 'all'));       // all|reserved|committed|released
$source     = trim((string)($_GET['source'] ?? ''));          // ''|live|manual  (schema: source_type)
$range      = trim((string)($_GET['range'] ?? '7dni'));       // zakres dat created_at
$start_date = trim((string)($_GET['start'] ?? ''));
$end_date   = trim((string)($_GET['end'] ?? ''));
$limitIn    = (string)($_GET['limit'] ?? '20');
$limit      = in_array($limitIn, ['20', '50', '100'], true) ? (int)$limitIn : 20;
$page       = max(1, (int)($_GET['page'] ?? 1));
$flashMsg   = $_GET['msg'] ?? '';

// Zakres dat (domyślny)
switch ($range) {
  case 'dzisiaj':
    $start_date = $end_date = date('Y-m-d');
    break;
  case 'wczoraj':
    $start_date = $end_date = date('Y-m-d', strtotime('-1 day'));
    break;
  case '7dni':
    $start_date = date('Y-m-d', strtotime('-7 days'));
    $end_date = date('Y-m-d');
    break;
  case '30dni':
    $start_date = date('Y-m-d', strtotime('-30 days'));
    $end_date = date('Y-m-d');
    break;
  case 'wszystkie':
    $start_date = '2000-01-01';
    $end_date = date('Y-m-d');
    break;
  default:
    $start_date = $start_date ?: date('Y-m-d');
    $end_date = $end_date ?: date('Y-m-d');
}

// -----------------------------
// Wspólne fragmenty zapytania
// -----------------------------
$baseParams = [
  ':owner_id'   => $owner_id,
  ':start_date' => $start_date . ' 00:00:00',
  ':end_date'   => $end_date   . ' 23:59:59',
];
$needsSearch = ($search !== '');
$needsSource = ($source !== '');
$needsStatus = ($status !== '' && $status !== 'all');

$baseWhere = "
WHERE p.owner_id = :owner_id
  AND sr.created_at BETWEEN :start_date AND :end_date
";
if ($needsSearch) {
  $baseWhere .= " AND (p.name LIKE :search OR p.code LIKE :search OR c.name LIKE :search) ";
}
if ($needsSource) {
  // schema V2: source_type
  $baseWhere .= " AND sr.source_type = :source_type ";
}
if ($needsStatus) {
  $baseWhere .= " AND sr.status = :status ";
}

$baseJoins = "
FROM stock_reservations sr
JOIN products p ON p.id = sr.product_id
LEFT JOIN clients c ON c.id = sr.client_id
";

// -----------------------------
// KPI (dla przefiltrowanego zbioru)
// -----------------------------
$sqlKpi = "
SELECT
  COUNT(*) AS rows_count,
  SUM(COALESCE(sr.qty,0)) AS total_qty,
  SUM(CASE WHEN sr.status='reserved' THEN COALESCE(sr.qty,0) ELSE 0 END) AS reserved_qty_only
$baseJoins
$baseWhere
";
$stKpi = $pdo->prepare($sqlKpi);
$stKpi->bindValue(':owner_id',   $owner_id, PDO::PARAM_INT);
$stKpi->bindValue(':start_date', $baseParams[':start_date']);
$stKpi->bindValue(':end_date',   $baseParams[':end_date']);
if ($needsSearch) $stKpi->bindValue(':search', '%' . $search . '%');
if ($needsSource) $stKpi->bindValue(':source_type', $source);
if ($needsStatus) $stKpi->bindValue(':status', $status);
$stKpi->execute();
$kpi = $stKpi->fetch(PDO::FETCH_ASSOC) ?: ['rows_count' => 0, 'total_qty' => 0, 'reserved_qty_only' => 0];

$total_rows  = (int)($kpi['rows_count'] ?? 0);
$total_pages = max(1, (int)ceil($total_rows / $limit));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;
$fromRow = ($total_rows === 0) ? 0 : ($offset + 1);
$toRow   = min($offset + $limit, $total_rows);

// KPI po źródłach (schema: source_type)
$sqlKpiSrc = "
SELECT sr.source_type, COUNT(*) AS cnt
$baseJoins
$baseWhere
GROUP BY sr.source_type
ORDER BY cnt DESC
";
$stKpiSrc = $pdo->prepare($sqlKpiSrc);
$stKpiSrc->bindValue(':owner_id',   $owner_id, PDO::PARAM_INT);
$stKpiSrc->bindValue(':start_date', $baseParams[':start_date']);
$stKpiSrc->bindValue(':end_date',   $baseParams[':end_date']);
if ($needsSearch) $stKpiSrc->bindValue(':search', '%' . $search . '%');
if ($needsSource) $stKpiSrc->bindValue(':source_type', $source);
if ($needsStatus) $stKpiSrc->bindValue(':status', $status);
$stKpiSrc->execute();
$kpiSources = $stKpiSrc->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------
// Lista (z miniaturą) – BEZ sr.updated_at
// -----------------------------
$sql = "
SELECT
  sr.id,
  sr.product_id,
  sr.client_id,
  sr.qty,
  sr.status,
  sr.source_type,
  sr.live_id,
  sr.created_at,
  p.name  AS product_name,
  p.code  AS product_code,
  c.name  AS client_name,
  (
    SELECT pi.image_path
    FROM product_images pi
    WHERE pi.product_id = p.id AND pi.is_main = 1
    ORDER BY pi.uploaded_at DESC, pi.id DESC
    LIMIT 1
  ) AS main_image
$baseJoins
$baseWhere
ORDER BY sr.created_at DESC
LIMIT :limit OFFSET :offset
";
$st = $pdo->prepare($sql);
$st->bindValue(':owner_id',   $owner_id, PDO::PARAM_INT);
$st->bindValue(':start_date', $baseParams[':start_date']);
$st->bindValue(':end_date',   $baseParams[':end_date']);
if ($needsSearch) $st->bindValue(':search', '%' . $search . '%');
if ($needsSource) $st->bindValue(':source_type', $source);
if ($needsStatus) $st->bindValue(':status', $status);
$st->bindValue(':limit',  $limit,  PDO::PARAM_INT);
$st->bindValue(':offset', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// helper do query string
function buildQuery(array $extra = []): string
{
  $base = $_GET;
  foreach ($extra as $k => $v) {
    if ($v === null) unset($base[$k]);
    else $base[$k] = $v;
  }
  return http_build_query($base);
}

// mapowanie źródeł (schema: source_type)
function renderSourceLabel(?string $src): string
{
  return match ($src) {
    'live'   => '📺 LIVE',
    'manual' => '🖐️ Manual',
    default  => '—'
  };
}

// badge statusu
function statusBadge($s)
{
  return match ($s) {
    'reserved'  => 'bg-yellow-100 text-yellow-800',
    'committed' => 'bg-emerald-100 text-emerald-800',
    'released'  => 'bg-gray-100 text-gray-700',
    default     => 'bg-gray-100 text-gray-700',
  };
}

// pretty qty (decimal -> obcięcie końcowych zer)
function pretty_qty($v): string
{
  $f = (float)$v;
  $s = number_format($f, 3, '.', '');
  return rtrim(rtrim($s, '0'), '.') ?: '0';
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css" />
<script src="https://cdn.jsdelivr.net/npm/litepicker/dist/litepicker.js"></script>

<style>
  .kpi-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    margin-bottom: 1rem
  }

  .kpi {
    display: flex;
    align-items: center;
    gap: .5rem;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: .5rem;
    padding: .4rem .6rem;
    font-size: .875rem
  }

  .kpi .val {
    font-weight: 700;
    color: #111827
  }

  .src-chip {
    background: #eef2ff;
    border: 1px solid #c7d2fe;
    color: #3730a3
  }

  .prod-img {
    width: 32px;
    height: 32px;
    object-fit: cover;
    border-radius: .375rem;
    border: 1px solid #e5e7eb
  }

  .pager a {
    padding: .25rem .5rem;
    border: 1px solid #e5e7eb;
    border-radius: .375rem;
    text-decoration: none
  }

  .pager a.active {
    background: #2563eb;
    color: #fff;
    border-color: #2563eb
  }

  .flash {
    padding: .5rem .75rem;
    border-radius: .5rem;
    margin-bottom: 1rem;
    font-size: .875rem
  }

  .flash-ok {
    background: #ecfdf5;
    border: 1px solid #10b98133;
    color: #065f46
  }

  .flash-err {
    background: #fef2f2;
    border: 1px solid #ef444433;
    color: #7f1d1d
  }

  .btn-del {
    color: #dc2626
  }

  .btn-del:hover {
    color: #b91c1c;
    text-decoration: underline
  }
</style>

<div class="max-w-7xl mx-auto px-4 py-5">



  <div class="mb-3">
    <a href="/admin/products/index.php" class="text-sm text-gray-500 hover:underline">← Wróć do produktów</a>
  </div>

  <h1 class="text-2xl font-bold mb-3">🧍 Rezerwacje (zbiorczo)</h1>

  <?php if ($flashMsg === 'released'): ?>
    <div class="flash flash-ok">Rezerwacja została zwolniona.</div>
  <?php elseif ($flashMsg === 'forbidden'): ?>
    <div class="flash flash-err">Brak uprawnień do modyfikacji tej rezerwacji.</div>
  <?php elseif ($flashMsg === 'dberror'): ?>
    <div class="flash flash-err">Wystąpił błąd bazy podczas operacji.</div>
  <?php endif; ?>

  <!-- KPI (dla zbioru po filtrach) -->
  <div class="kpi-wrap">
    <div class="kpi">Pozycji: <span class="val"><?= number_format($total_rows, 0, ',', ' ') ?></span></div>
    <div class="kpi">Sztuk: <span class="val"><?= pretty_qty($kpi['total_qty'] ?? 0) ?></span></div>
    <div class="kpi">Tylko „reserved”: <span class="val"><?= pretty_qty($kpi['reserved_qty_only'] ?? 0) ?></span></div>
    <div class="kpi">⏱ <?= htmlspecialchars($start_date) ?> → <?= htmlspecialchars($end_date) ?></div>
    <?php foreach ($kpiSources as $s): $src = (string)($s['source_type'] ?? '');
      $cnt = (int)$s['cnt']; ?>
      <div class="kpi src-chip"><?= renderSourceLabel($src) ?>: <span class="val"><?= $cnt ?></span></div>
    <?php endforeach; ?>
  </div>

  <!-- Filtry -->
  <form method="get" class="flex flex-wrap gap-2 mb-4 items-center">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Szukaj: produkt / kod / klient" class="border p-2 rounded" />

    <select name="status" class="border p-2 rounded">
      <?php
      $statuses = ['all' => 'Wszystkie', 'reserved' => 'reserved', 'committed' => 'committed', 'released' => 'released'];
      foreach ($statuses as $val => $label) {
        echo "<option value=\"$val\"" . ($val === $status ? ' selected' : '') . ">$label</option>";
      }
      ?>
    </select>

    <select name="source" class="border p-2 rounded" title="Źródło rezerwacji (schema: source_type)">
      <option value="">Wszystkie źródła</option>
      <?php
      $sources = ['live' => 'LIVE', 'manual' => 'Manual'];
      foreach ($sources as $val => $label) {
        echo "<option value=\"$val\"" . ($val === $source ? ' selected' : '') . ">$label</option>";
      }
      ?>
    </select>

    <select name="range" class="border p-2 rounded">
      <?php
      $ranges = [
        'dzisiaj'   => '📆 Dzisiaj',
        'wczoraj'   => '📅 Wczoraj',
        '7dni'      => '🗓️ Ostatnie 7 dni',
        '30dni'     => '📈 Ostatnie 30 dni',
        'wszystkie' => '♾️ Wszystkie',
        'wlasny'    => '📂 Własny zakres'
      ];
      foreach ($ranges as $key => $label) {
        echo "<option value=\"$key\"" . ($key === $range ? ' selected' : '') . ">$label</option>";
      }
      ?>
    </select>

    <input type="text" id="daterange" class="border p-2 rounded" placeholder="Zakres dat" readonly />
    <input type="hidden" name="start" id="start-date" value="<?= htmlspecialchars($start_date) ?>">
    <input type="hidden" name="end" id="end-date" value="<?= htmlspecialchars($end_date) ?>">

    <select name="limit" class="border p-2 rounded" onchange="this.form.submit()">
      <option value="20" <?= $limit === 20  ? 'selected' : '' ?>>20 / str</option>
      <option value="50" <?= $limit === 50  ? 'selected' : '' ?>>50 / str</option>
      <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100 / str</option>
    </select>

    <button class="bg-blue-500 text-white px-4 py-2 rounded">🔍 Filtruj</button>
    <a href="?<?= buildQuery(['export' => 'csv']) ?>" class="bg-green-500 text-white px-4 py-2 rounded">⬇️ Eksport CSV</a>
  </form>

  <?php
  // Eksport CSV aktualnej strony (po filtrach)
  if ((isset($_GET['export']) && $_GET['export'] === 'csv')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment;filename=rezerwacje.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Data', 'Kod', 'Produkt', 'Klient', 'Ilość', 'Źródło', 'Status']);
    foreach ($rows as $r) {
      fputcsv($out, [
        (string)($r['created_at'] ?? ''),
        (string)($r['product_code'] ?? ''),
        (string)($r['product_name'] ?? ''),
        (string)($r['client_name'] ?? ''),
        pretty_qty($r['qty'] ?? 0),
        (string)($r['source_type'] ?? ''),
        (string)($r['status'] ?? ''),
      ]);
    }
    fclose($out);
    exit;
  }
  ?>

  <!-- Lista -->
  <div class="overflow-auto">
    <table class="min-w-full border text-sm">
      <thead>
        <tr class="bg-gray-100">
          <th class="p-2 text-left">Produkt</th>
          <th class="p-2 text-left">Klient</th>
          <th class="p-2 text-center">Ilość</th>
          <th class="p-2 text-center">Źródło</th>
          <th class="p-2 text-center">Status</th>
          <th class="p-2 text-left">Utworzono</th>
          <th class="p-2 text-left">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $imgPath = !empty($r['main_image'])
            ? '/uploads/products/' . htmlspecialchars($r['main_image'])
            : '/uploads/products/placeholder.jpg';
        ?>
          <tr class="border-b hover:bg-gray-50">
            <td class="p-2">
              <div class="flex items-center gap-2">
                <img src="<?= $imgPath ?>" class="prod-img" alt="">
                <div>
                  <div class="font-medium text-gray-800">
                    <a href="/admin/products/waitlist.php?product_id=<?= (int)$r['product_id'] ?>" class="hover:underline">
                      <?= htmlspecialchars($r['product_name'] ?? '') ?>
                    </a>
                  </div>
                  <div class="text-xs text-gray-500 font-mono"><?= htmlspecialchars($r['product_code'] ?? '') ?></div>
                </div>
              </div>
            </td>

            <td class="p-2">
              <?php if (!empty($r['client_id'])): ?>
                <a href="/admin/clients/edit.php?id=<?= (int)$r['client_id'] ?>" class="hover:underline text-gray-800">
                  <?= htmlspecialchars($r['client_name'] ?? '—') ?>
                </a>
              <?php else: ?>
                <?= htmlspecialchars($r['client_name'] ?? '—') ?>
              <?php endif; ?>
            </td>

            <td class="p-2 text-center"><?= pretty_qty($r['qty'] ?? 0) ?></td>

            <td class="p-2 text-center">
              <span class="px-2 py-1 rounded text-xs bg-indigo-100 text-indigo-800">
                <?= renderSourceLabel($r['source_type'] ?? null) ?>
                <?php if (!empty($r['live_id'])): ?>
                  <span class="ml-1 text-[10px] opacity-70">#<?= (int)$r['live_id'] ?></span>
                <?php endif; ?>
              </span>
            </td>

            <td class="p-2 text-center">
              <span class="px-2 py-1 rounded text-xs <?= statusBadge($r['status']) ?>">
                <?= htmlspecialchars($r['status'] ?? '—') ?>
              </span>
            </td>

            <td class="p-2 text-gray-500 text-sm">
              <?= $r['created_at'] ? date('Y-m-d H:i', strtotime($r['created_at'])) : '—' ?>
            </td>

            <td class="p-2">
              <div class="flex items-center gap-3">
                <a href="/admin/products/waitlist.php?product_id=<?= (int)$r['product_id'] ?>" class="text-blue-600 hover:underline">Podejrzyj</a>

                <form action="/admin/products/notify_client.php" method="post" style="display:inline">
                  <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="text-blue-600 hover:underline">✉️ Powiadom</button>
                </form>

                <?php if (($r['status'] ?? '') === 'reserved'): ?>
                  <form method="post"
                    action="/admin/products/rezerwacje.php?<?= buildQuery([]) ?>"
                    onsubmit="return confirm('Zwolnić rezerwację?');"
                    style="display:inline">
                    <input type="hidden" name="release_reservation_id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="btn-del">🗑️ Zwolnij</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginacja -->
  <div class="mt-3 flex items-center justify-between">
    <div class="text-sm text-gray-600">
      Wyświetlono <strong><?= $fromRow ?></strong>–<strong><?= $toRow ?></strong> z <strong><?= number_format($total_rows, 0, ',', ' ') ?></strong>
    </div>
    <div class="pager flex gap-1">
      <?php if ($page > 1): ?>
        <a href="?<?= buildQuery(['page' => 1]) ?>">&laquo;</a>
        <a href="?<?= buildQuery(['page' => $page - 1]) ?>">&lsaquo;</a>
      <?php endif; ?>
      <?php
      $win = 2;
      $start = max(1, $page - $win);
      $end = min($total_pages, $page + $win);
      for ($i = $start; $i <= $end; $i++):
      ?>
        <a href="?<?= buildQuery(['page' => $i]) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <?php if ($page < $total_pages): ?>
        <a href="?<?= buildQuery(['page' => $page + 1]) ?>">&rsaquo;</a>
        <a href="?<?= buildQuery(['page' => $total_pages]) ?>">&raquo;</a>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
  // Litepicker – zakres dat
  const picker = new Litepicker({
    element: document.getElementById('daterange'),
    singleMode: false,
    numberOfMonths: 2,
    numberOfColumns: 2,
    format: 'YYYY-MM-DD',
    startDate: '<?= $start_date ?>',
    endDate: '<?= $end_date ?>',
    dropdowns: {
      minYear: 2023,
      maxYear: new Date().getFullYear(),
      months: true,
      years: true
    },
    setup: (p) => {
      p.on('selected', (start, end) => {
        if (start) document.getElementById('start-date').value = start.format('YYYY-MM-DD');
        if (end) document.getElementById('end-date').value = end.format('YYYY-MM-DD');
      });
    },
  });
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php';
