<?php
// admin/products/kupione.php — 📦 Kupione produkty (is_prepared = szybki bezpiecznik operatora)
// Wersja: code z products.code + kolumna Źródło przed Datą
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($owner_id <= 0) {
  http_response_code(403);
  exit('Brak owner_id');
}

// ---------- Helpers ----------
function e(?string $s): string
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function buildQuery(array $extra = []): string
{
  $base = $_GET;
  foreach ($extra as $k => $v) {
    if ($v === null) unset($base[$k]);
    else $base[$k] = $v;
  }
  return http_build_query($base);
}

// ---------- POST: delete (z CSRF) ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_item_id'])) {
  if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(400);
    exit('CSRF mismatch');
  }
  $deleteId = (int)$_POST['delete_item_id'];
  try {
    $chk = $pdo->prepare("SELECT 1
      FROM order_items oi
      JOIN orders o ON o.id=oi.order_id
      WHERE oi.id=:id AND o.owner_id=:oid LIMIT 1");
    $chk->execute([':id' => $deleteId, ':oid' => $owner_id]);
    if ($chk->fetchColumn()) {
      $pdo->prepare("DELETE FROM order_items WHERE id=:id LIMIT 1")->execute([':id' => $deleteId]);
      logg('info', 'products.kupione', 'delete_item', ['id' => $deleteId], ['owner_id' => $owner_id, 'source' => 'panel']);
      $msg = 'deleted';
    } else {
      $msg = 'forbidden';
    }
  } catch (Throwable $e) {
    logg('error', 'products.kupione', 'delete_fail', ['message' => $e->getMessage()], ['owner_id' => $owner_id, 'trace' => $e->getTraceAsString()]);
    $msg = 'dberror';
  }
  header('Location: /admin/products/kupione.php?' . buildQuery(['msg' => $msg]));
  exit;
}

// ---------- Filtry ----------
$search   = trim((string)($_GET['q'] ?? ''));
$prepared = trim((string)($_GET['prepared'] ?? '')); // '', '0', '1'
$range    = trim((string)($_GET['range'] ?? '7dni'));
$limit    = (int)($_GET['limit'] ?? 50);
if (!in_array($limit, [20, 50, 100], true)) $limit = 50;
$page     = max(1, (int)($_GET['page'] ?? 1));
$flashMsg = (string)($_GET['msg'] ?? '');

switch ($range) {
  case 'dzisiaj':
    $start = $end = date('Y-m-d');
    break;
  case 'wczoraj':
    $start = $end = date('Y-m-d', strtotime('-1 day'));
    break;
  case '30dni':
    $start = date('Y-m-d', strtotime('-30 days'));
    $end = date('Y-m-d');
    break;
  case 'wszystkie':
    $start = '2000-01-01';
    $end = date('Y-m-d');
    break;
  default:
    $start = date('Y-m-d', strtotime('-7 days'));
    $end = date('Y-m-d');
    break;
}

// ---------- WHERE ----------
$where = "WHERE o.owner_id=:oid AND oi.created_at BETWEEN :s AND :e";
$params = [':oid' => $owner_id, ':s' => "$start 00:00:00", ':e' => "$end 23:59:59"];

if ($search !== '') {
  $where .= " AND (oi.name LIKE :q OR p.code LIKE :q OR c.name LIKE :q)";
  $params[':q'] = "%$search%";
}
if ($prepared === '1') $where .= " AND oi.is_prepared=1";
if ($prepared === '0') $where .= " AND oi.is_prepared=0";

// ---------- KPI ----------
$sqlKpi = "SELECT COUNT(*) cnt, SUM(oi.qty) qty_sum, SUM(oi.total_price) total_sum
FROM order_items oi
JOIN orders o  ON o.id=oi.order_id
JOIN clients c ON c.id=o.client_id
LEFT JOIN products p ON p.id=oi.product_id
$where";
$st = $pdo->prepare($sqlKpi);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->execute();
$kpi = $st->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'qty_sum' => 0, 'total_sum' => 0];

$total_rows  = (int)$kpi['cnt'];
$total_pages = max(1, (int)ceil($total_rows / $limit));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $limit;
$fromRow     = $total_rows ? $offset + 1 : 0;
$toRow       = min($offset + $limit, $total_rows);

// ---------- Lista (code z products.code + Źródło przed Datą) ----------
$sql = "SELECT
  oi.id, oi.order_id, oi.order_group_id,
  oi.product_id, p.code, oi.name, oi.qty, oi.unit_price, oi.total_price,
  oi.is_prepared, oi.note, oi.created_at, oi.source_type,
  c.id AS client_id, c.name AS client_name
FROM order_items oi
JOIN orders o  ON o.id=oi.order_id
JOIN clients c ON c.id=o.client_id
LEFT JOIN products p ON p.id=oi.product_id
$where
ORDER BY oi.created_at DESC
LIMIT :l OFFSET :o";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':l', $limit, PDO::PARAM_INT);
$st->bindValue(':o', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// ---------- Widok ----------
require_once __DIR__ . '/../../layout/layout_header.php';
?>
<style>
  .chip {
    display: inline-flex;
    gap: .35rem;
    align-items: center;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 9999px;
    padding: .25rem .5rem;
    font-size: .8rem
  }

  .kpi {
    display: flex;
    gap: .5rem;
    flex-wrap: wrap;
    margin: .75rem 0
  }

  .kpi .box {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: .5rem;
    padding: .4rem .6rem;
    font-size: .9rem
  }

  .prep-btn {
    transition: .12s
  }

  .prep-btn.on {
    background: #dcfce7;
    border-color: #86efac;
    color: #166534
  }

  .prep-btn.off {
    background: #f1f5f9;
    border-color: #e2e8f0;
    color: #334155
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

  thead th {
    background: #f8fafc
  }
</style>

<div class="p-6">
  <div class="mb-2"><a href="/admin/products/index.php" class="text-sm text-gray-500 hover:underline">← Wróć do produktów</a></div>
  <h1 class="text-2xl font-bold">📦 Kupione produkty</h1>

  <?php if ($flashMsg === 'deleted'): ?>
    <div class="mt-2 p-2 rounded bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">Pozycja została usunięta.</div>
  <?php elseif ($flashMsg === 'forbidden'): ?>
    <div class="mt-2 p-2 rounded bg-yellow-50 border border-yellow-200 text-yellow-800 text-sm">Brak uprawnień do usunięcia tej pozycji.</div>
  <?php elseif ($flashMsg === 'dberror'): ?>
    <div class="mt-2 p-2 rounded bg-red-50 border border-red-200 text-red-700 text-sm">Błąd bazy podczas usuwania.</div>
  <?php endif; ?>

  <div class="kpi">
    <div class="box">Pozycji: <b><?= number_format($total_rows, 0, ',', ' ') ?></b></div>
    <div class="box">Sztuk: <b><?= number_format((float)$kpi['qty_sum'], 0, ',', ' ') ?></b></div>
    <div class="box">Obrót: <b><?= number_format((float)$kpi['total_sum'], 2, ',', ' ') ?> zł</b></div>
    <div class="box">⏱ <?= e($start) ?> → <?= e($end) ?></div>
  </div>

  <!-- Filtry -->
  <form method="get" class="flex flex-wrap items-center gap-2 mb-3">
    <input name="q" value="<?= e($search) ?>" class="border rounded px-3 py-2" placeholder="Szukaj (produkt / CODE / klient)" />
    <select name="prepared" class="border rounded px-2 py-2">
      <option value="" <?= $prepared === '' ? 'selected' : '' ?>>📦 Wszystkie</option>
      <option value="1" <?= $prepared === '1' ? 'selected' : '' ?>>✅ Tylko odłożone</option>
      <option value="0" <?= $prepared === '0' ? 'selected' : '' ?>>❌ Tylko nieodłożone</option>
    </select>
    <select name="range" class="border rounded px-2 py-2">
      <option value="dzisiaj" <?= $range === 'dzisiaj' ? 'selected' : '' ?>>📆 Dzisiaj</option>
      <option value="wczoraj" <?= $range === 'wczoraj' ? 'selected' : '' ?>>📅 Wczoraj</option>
      <option value="7dni" <?= $range === '7dni' ? 'selected' : '' ?>>🗓️ 7 dni</option>
      <option value="30dni" <?= $range === '30dni' ? 'selected' : '' ?>>📈 30 dni</option>
      <option value="wszystkie" <?= $range === 'wszystkie' ? 'selected' : '' ?>>♾️ Wszystkie</option>
    </select>
    <select name="limit" class="border rounded px-2 py-2" onchange="this.form.submit()">
      <option value="20" <?= $limit === 20 ? 'selected' : '' ?>>20 / str</option>
      <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50 / str</option>
      <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100 / str</option>
    </select>
    <button class="px-3 py-2 rounded bg-blue-600 text-white">🔍 Filtruj</button>
    <span class="ml-auto text-xs text-slate-500">Skrót: <span class="chip">/</span> fokus w szukajce</span>
  </form>

  <div class="overflow-auto">
    <table class="min-w-full border text-sm">
      <thead>
        <tr>
          <th class="p-2 w-10"><input type="checkbox" disabled></th>
          <th class="p-2 text-center">📦</th>
          <th class="p-2">Code</th>
          <th class="p-2">Produkt</th>
          <th class="p-2 text-right">Ilość</th>
          <th class="p-2 text-right">Cena/szt</th>
          <th class="p-2 text-right">Wartość</th>
          <th class="p-2">Klient</th>
          <th class="p-2 text-center">Źródło</th>
          <th class="p-2">Data</th>
          <th class="p-2 text-right">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $is = (int)$r['is_prepared'];
          $btnClass = $is ? 'prep-btn on' : 'prep-btn off';
          $btnText  = $is ? '✓ odłożone' : 'odłóż';
        ?>
          <tr class="border-b hover:bg-gray-50">
            <td class="p-2 text-center"><input type="checkbox" disabled></td>

            <td class="p-2 text-center">
              <button
                type="button"
                class="<?= $btnClass ?> px-2 py-1 border rounded text-xs"
                data-id="<?= (int)$r['id'] ?>"
                data-state="<?= $is ?>"
                title="<?= e((string)($r['note'] ?? '')) ?>">
                <?= $btnText ?>
              </button>
            </td>

            <td class="p-2 font-mono"><?= e($r['code'] ?? '') ?></td>
            <td class="p-2"><?= e($r['name']) ?></td>

            <td class="p-2 text-right"><?= number_format((float)$r['qty'], 3, ',', ' ') ?></td>
            <td class="p-2 text-right"><?= number_format((float)$r['unit_price'], 2, ',', ' ') ?> zł</td>
            <td class="p-2 text-right"><?= number_format((float)$r['total_price'], 2, ',', ' ') ?> zł</td>

            <td class="p-2">
              <a class="text-blue-700 hover:underline" href="/admin/clients/edit.php?id=<?= (int)$r['client_id'] ?>"><?= e($r['client_name']) ?></a>
            </td>

            <td class="p-2 text-center">
              <?php
              echo match ($r['source_type'] ?? '') {
                'live'    => '📺 Live',
                'shop'    => '🛒 Sklep',
                'parser'  => '🤖 Parser',
                'manual'  => '🖐️ Panel',
                default   => e($r['source_type'] ?? '—'),
              };
              ?>
            </td>

            <td class="p-2 text-slate-500"><?= e($r['created_at']) ?></td>

            <td class="p-2 text-right">
              <form method="post" onsubmit="return confirm('Usunąć tę pozycję?')" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="delete_item_id" value="<?= (int)$r['id'] ?>">
                <button class="text-red-600 hover:underline">🗑️ Usuń</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="11" class="p-6 text-center text-slate-500">Brak wyników dla wybranych filtrów.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginacja -->
  <div class="mt-3 flex items-center justify-between">
    <div class="text-sm text-gray-600">
      Wyświetlono <b><?= $fromRow ?></b>–<b><?= $toRow ?></b> z <b><?= number_format($total_rows, 0, ',', ' ') ?></b>
    </div>
    <div class="pager flex gap-1">
      <?php if ($page > 1): ?>
        <a href="?<?= buildQuery(['page' => 1]) ?>">&laquo;</a>
        <a href="?<?= buildQuery(['page' => $page - 1]) ?>">&lsaquo;</a>
      <?php endif; ?>
      <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
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
  // Fokus w szukajkę pod klawisz "/"
  window.addEventListener('keydown', (e) => {
    if (e.key === '/' && !e.ctrlKey && !e.metaKey) {
      const el = document.querySelector('input[name="q"]');
      if (!el) return;
      e.preventDefault();
      el.focus();
      el.select();
    }
  });

  // Toggle prepared
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.prep-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        const current = btn.dataset.state === '1' ? 1 : 0;
        const want = current ? 0 : 1;

        try {
          const res = await fetch('/admin/products/ajax_toggle_flag.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: new URLSearchParams({
              order_item_id: btn.dataset.id,
              set: want,
              csrf_token: '<?= e($csrf) ?>'
            })
          });
          const j = await res.json();
          if (!res.ok || !j.ok) throw new Error(j.error || 'db');

          // update UI
          btn.dataset.state = String(j.is_set);
          if (j.is_set == 1) {
            btn.classList.add('on');
            btn.classList.remove('off');
            btn.textContent = '✓ odłożone';
          } else {
            btn.classList.add('off');
            btn.classList.remove('on');
            btn.textContent = 'odłóż';
          }
        } catch (err) {
          alert('❌ Błąd: ' + (err.message || 'db'));
        } finally {
          btn.disabled = false;
        }
      });
    });
  });
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>