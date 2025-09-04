<?php
// admin/clients/index.php — V4: lista klientów (stabilne sortowanie, DISTINCT, paginacja)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($owner_id <= 0) {
  http_response_code(403);
  exit('Brak kontekstu właściciela');
}

// --- sorty (whitelist + mapowanie kolumn) ---
$sortable_columns = ['name', 'email', 'phone', 'token', 'last_seen', 'order_count'];
$sort = in_array(($_GET['sort'] ?? ''), $sortable_columns, true) ? (string)$_GET['sort'] : 'last_seen';
$dir  = (($_GET['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

// przygotuj ORDER BY (ze wsparciem NULL last dla last_seen)
switch ($sort) {
  case 'name':
    $order_by = "c.name {$dir}";
    break;
  case 'email':
    $order_by = "c.email {$dir}";
    break;
  case 'phone':
    $order_by = "c.phone {$dir}";
    break;
  case 'token':
    $order_by = "c.token {$dir}";
    break;
  case 'order_count':
    // alias dostępny po GROUP BY
    $order_by = "order_count {$dir}";
    break;
  case 'last_seen':
  default:
    // NULL-e zawsze na końcu, niezależnie od kierunku
    $secondary = ($dir === 'asc') ? 'ASC' : 'DESC';
    $order_by = "(CASE WHEN c.last_seen IS NULL THEN 1 ELSE 0 END) ASC, c.last_seen {$secondary}";
    break;
}

// --- paginacja ---
$per_allowed = [25, 50, 100];
$per_page = (int)($_GET['per_page'] ?? 25);
if (!in_array($per_page, $per_allowed, true)) $per_page = 25;
$page   = max((int)($_GET['page'] ?? 1), 1);
$offset = ($page - 1) * $per_page;

// --- search ---
$search = trim((string)($_GET['q'] ?? ''));
$search_sql = '';
if ($search !== '') {
  $search_sql = " AND (
      c.name  LIKE :q1
   OR c.email LIKE :q2
   OR c.phone LIKE :q3
   OR c.token LIKE :q4
  ) ";
}

// --- count (DISTINCT, by nie dublować przez JOIN-y) ---
$count_sql = "SELECT COUNT(DISTINCT c.id)
              FROM clients c
              WHERE c.owner_id = :owner_id {$search_sql}";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->bindValue(':owner_id', $owner_id, PDO::PARAM_INT);
if ($search !== '') {
  $w = '%' . $search . '%';
  $count_stmt->bindValue(':q1', $w, PDO::PARAM_STR);
  $count_stmt->bindValue(':q2', $w, PDO::PARAM_STR);
  $count_stmt->bindValue(':q3', $w, PDO::PARAM_STR);
  $count_stmt->bindValue(':q4', $w, PDO::PARAM_STR);
}
$count_stmt->execute();
$total_clients = (int)$count_stmt->fetchColumn();
$total_pages   = max((int)ceil($total_clients / $per_page), 1);
if ($page > $total_pages) {
  $page = $total_pages;
  $offset = ($page - 1) * $per_page;
}

// --- listing ---
$sql = "SELECT 
          c.id, c.name, c.email, c.phone, c.token, c.last_seen,
          COUNT(DISTINCT o.id) AS order_count
        FROM clients c
        LEFT JOIN orders o ON o.client_id = c.id
        WHERE c.owner_id = :owner_id {$search_sql}
        GROUP BY c.id
        ORDER BY {$order_by}
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':owner_id', $owner_id, PDO::PARAM_INT);
if ($search !== '') {
  $w = '%' . $search . '%';
  $stmt->bindValue(':q1', $w, PDO::PARAM_STR);
  $stmt->bindValue(':q2', $w, PDO::PARAM_STR);
  $stmt->bindValue(':q3', $w, PDO::PARAM_STR);
  $stmt->bindValue(':q4', $w, PDO::PARAM_STR);
}
$stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
$stmt->execute();
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- helpers UI ---
if (!function_exists('th_sort')) {
  function th_sort(string $label, string $column, string $sort, string $dir, string $search, int $per_page): string
  {
    $icon = '';
    if ($sort === $column) $icon = $dir === 'asc' ? '▲' : '▼';
    $nextDir = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';
    $url = '?sort=' . urlencode($column) . '&dir=' . urlencode($nextDir)
      . '&q=' . urlencode($search) . '&per_page=' . $per_page;
    return "<th class='px-4 py-2 text-left font-semibold whitespace-nowrap'><a href='{$url}'>{$label} {$icon}</a></th>";
  }
}

include __DIR__ . '/../../layout/layout_header.php';

?>
<div class="flex flex-col h-screen">
  <main class="flex-1 overflow-y-auto px-6 py-2">
    <div class="flex items-center justify-between mb-4">
      <div class="flex items-center gap-4">
        <a href="../" class="text-sm text-blue-600 hover:underline flex items-center gap-1">
          <span class="text-lg">←</span> Wróć
        </a>
        <h1 class="text-2xl font-bold">👥 Klienci</h1>
      </div>

      <?php if (($_SESSION['user']['role'] ?? '') === 'superadmin'): ?>
        <a href="create.php" class="bg-green-600 text-white px-4 py-2 rounded shadow hover:bg-green-700 transition">
          ➕ Dodaj klienta
        </a>
      <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['success_message'])): ?>
      <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
        <?= htmlspecialchars((string)$_SESSION['success_message']) ?>
      </div>
      <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <div class="mb-4 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
      <div class="flex flex-wrap gap-2 text-sm" id="pagination">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
          <a href="?page=<?= $i ?>&per_page=<?= $per_page ?>&q=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>&dir=<?= urlencode($dir) ?>"
            class="px-3 py-1 rounded border <?= $i == $page ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-blue-600 border-blue-600' ?>">
            <?= $i ?>
          </a>
        <?php endfor; ?>
      </div>

      <div class="flex gap-2 w-full md:w-1/2">
        <input type="text" id="liveSearch" value="<?= htmlspecialchars($search) ?>" placeholder="Szukaj po imieniu, e-mailu, telefonie, tokenie..."
          class="border px-3 py-2 rounded w-full">
      </div>

      <form method="get" class="text-sm">
        <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
        <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
        <label for="per_page">Rekordów na stronę:</label>
        <select name="per_page" id="per_page" onchange="this.form.submit()" class="ml-2 border px-2 py-1 rounded">
          <?php foreach ([25, 50, 100] as $opt): ?>
            <option value="<?= $opt ?>" <?= $per_page == $opt ? 'selected' : '' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full table-auto text-sm border-collapse" id="clientsTable">
        <thead class="sticky top-0 bg-gray-100 shadow-sm z-10">
          <tr>
            <th class="px-2"><input type="checkbox" id="checkAll"></th>
            <?= th_sort('Imię i nazwisko', 'name', $sort, $dir, $search, $per_page) ?>
            <?= th_sort('E-mail', 'email', $sort, $dir, $search, $per_page) ?>
            <?= th_sort('Telefon', 'phone', $sort, $dir, $search, $per_page) ?>
            <?= th_sort('Token', 'token', $sort, $dir, $search, $per_page) ?>
            <?= th_sort('Ostatnio widziany', 'last_seen', $sort, $dir, $search, $per_page) ?>
            <?= th_sort('Zamówienia', 'order_count', $sort, $dir, $search, $per_page) ?>
            <th class="px-2 py-2 font-semibold">Status</th>
            <th class="px-2 py-2 text-center font-semibold w-20">Szczegóły</th>
          </tr>
        </thead>
        <tbody id="clientRows">
          <?php foreach ($clients as $client): ?>
            <tr class="border-b hover:bg-gray-50 transition client-row">
              <td class="p-2 text-center"><input type="checkbox" class="row-check" value="<?= $client['id'] ?>"></td>
              <td class="p-2 font-medium flex items-center gap-2">
                <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-xs font-bold text-blue-700">
                  <?= strtoupper(mb_substr($client['name'] ?? '?', 0, 1)) ?>
                </div>
                <?= htmlspecialchars($client['name'] ?? '') ?>
              </td>
              <td class="p-2"><?= htmlspecialchars($client['email'] ?? '') ?></td>
              <td class="p-2"><?= htmlspecialchars($client['phone'] ?? '') ?></td>
              <td class="p-2 text-xs text-gray-500"><?= htmlspecialchars($client['token'] ?? '') ?></td>
              <td class="p-2 text-sm text-gray-600">
                <?= $client['last_seen'] ? date('Y-m-d H:i', strtotime($client['last_seen'])) : '-' ?>
              </td>
              <td class="p-2 text-center"><?= $client['order_count'] ?></td>
              <td class="p-2">
                <span class="px-2 py-0.5 rounded text-xs <?= $client['order_count'] > 0 ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                  <?= $client['order_count'] > 0 ? 'aktywny' : 'brak zam.' ?>
                </span>
              </td>
              <td class="p-2 text-right">
                <a href="view.php?id=<?= $client['id'] ?>" class="text-blue-600 hover:underline">Szczegóły</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

    </div>
  </main>

  <?php include __DIR__ . '/../../layout/layout_footer.php'; ?>
</div>

<script>
  // live debounce search
  const searchInput = document.getElementById('liveSearch');
  let timeout = null;
  searchInput.addEventListener('input', function() {
    clearTimeout(timeout);
    timeout = setTimeout(() => {
      const q = searchInput.value || '';
      const params = new URLSearchParams(window.location.search);
      params.set('q', q);
      params.set('page', '1');
      window.location.search = params.toString();
    }, 350);
  });
</script>
<div class="mt-3 flex gap-2">
  <button id="bulkExport" class="bg-gray-200 px-3 py-1 rounded">📤 Eksport</button>
  <button id="bulkDelete" class="bg-red-600 text-white px-3 py-1 rounded">🗑 Usuń</button>
</div>

<script>
  const checkAll = document.getElementById('checkAll');
  const rowChecks = document.querySelectorAll('.row-check');
  checkAll?.addEventListener('change', () => {
    rowChecks.forEach(cb => cb.checked = checkAll.checked);
  });
  document.getElementById('bulkExport')?.addEventListener('click', () => {
    const ids = Array.from(rowChecks).filter(cb => cb.checked).map(cb => cb.value);
    if (ids.length) window.location = 'export.php?ids=' + ids.join(',');
  });
  document.getElementById('bulkDelete')?.addEventListener('click', () => {
    const ids = Array.from(rowChecks).filter(cb => cb.checked).map(cb => cb.value);
    if (ids.length && confirm('Na pewno usunąć zaznaczonych klientów?')) {
      fetch('delete_bulk.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            ids
          })
        })
        .then(() => location.reload());
    }
  });
</script>