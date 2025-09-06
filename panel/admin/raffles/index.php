<?php
// admin/raffles/index.php — Olaj.pl V4 (Losowania / Raffles)
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';

if (!function_exists('h')) {
  function h($s)
  {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 1);

// ─────────────────────────────────────────────────────────────
// Parametry filtrów
// ─────────────────────────────────────────────────────────────
$status  = trim((string)($_GET['status'] ?? 'all'));
$q       = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));
$offset  = ($page - 1) * $perPage;

// ─────────────────────────────────────────────────────────────
// Log — lekko, żeby było śledzenie użycia widoku
// ─────────────────────────────────────────────────────────────
if (function_exists('logg')) {
  logg('info', 'ui.raffles', 'index_open', [
    'owner_id' => $ownerId,
    'status'   => $status,
    'q'        => $q,
    'page'     => $page,
    'perPage'  => $perPage,
  ]);
}

// ─────────────────────────────────────────────────────────────
// Budowa WHERE
// ─────────────────────────────────────────────────────────────
$where = ['d.owner_id = :oid'];
$params = [':oid' => $ownerId];

if ($status !== '' && $status !== 'all') {
  $where[] = 'd.status = :status';
  $params[':status'] = $status;
}

if ($q !== '') {
  // Szukaj po tytule losowania lub tytule LIVE
  $where[] = '(d.title LIKE :q OR COALESCE(ls.title, \'\') LIKE :q)';
  $params[':q'] = '%' . $q . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ─────────────────────────────────────────────────────────────
// Licznik rekordów do paginacji
// ─────────────────────────────────────────────────────────────
$countSql = "SELECT COUNT(*) AS cnt
             FROM draws d
             LEFT JOIN live_streams ls ON ls.id = d.live_stream_id
             $whereSql";
$stCount = $pdo->prepare($countSql);
$stCount->execute($params);
$total = (int)$stCount->fetchColumn();

// ─────────────────────────────────────────────────────────────
// Pobranie listy (uwaga: participants_count z kolumny; jeśli
// przejdziesz na draw_entries, podstaw subselect COUNT(*))
// ─────────────────────────────────────────────────────────────
$listSql = "SELECT
              d.*,
              COALESCE(ls.title,'(offline)') AS live_title
              -- Przykład, gdybyś liczył z entries:
              -- , (SELECT COUNT(*) FROM draw_entries de WHERE de.raffle_id = d.id) AS participants_count
            FROM draws d
            LEFT JOIN live_streams ls ON ls.id = d.live_stream_id
            $whereSql
            ORDER BY d.created_at DESC
            LIMIT :lim OFFSET :off";

$stList = $pdo->prepare($listSql);

// bindowanie limit/offset jako int (PDO+mysql wymaga bindValue z param typem int)
foreach ($params as $k => $v) {
  $stList->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stList->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stList->bindValue(':off', $offset, PDO::PARAM_INT);
$stList->execute();
$draws = $stList->fetchAll(PDO::FETCH_ASSOC);

// Strony
$totalPages = (int)max(1, ceil($total / $perPage));

require_once __DIR__ . '/../../layout/layout_header.php';
?>
<div class="max-w-6xl mx-auto p-4">
  <div class="flex items-center justify-between mb-4">
    <h1 class="text-2xl font-bold">🎲 Losowania</h1>
    <a href="create.php" class="inline-flex items-center rounded-lg px-3 py-2 bg-blue-600 text-white hover:bg-blue-700 transition">
      ➕ Utwórz nowe losowanie
    </a>
  </div>

  <form method="get" class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
    <div>
      <label class="block text-sm font-medium mb-1">Status</label>
      <select name="status" class="w-full rounded border px-3 py-2" onchange="this.form.submit()">
        <?php
        $statuses = ['all' => '— wszystkie —', 'draft' => 'draft', 'arming' => 'arming', 'frozen' => 'frozen', 'drawn' => 'drawn', 'closed' => 'closed'];
        foreach ($statuses as $value => $label):
        ?>
          <option value="<?= h($value) ?>" <?= ($status === $value || ($value === 'all' && ($status === '' || $status === 'all'))) ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:col-span-2">
      <label class="block text-sm font-medium mb-1">Szukaj (tytuł lub LIVE)</label>
      <div class="flex gap-2">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="np. 'Giveaway', 'Niedzielny LIVE'…" class="w-full rounded border px-3 py-2" />
        <button class="rounded px-3 py-2 border hover:bg-gray-50">Szukaj</button>
      </div>
    </div>
  </form>

  <div class="overflow-x-auto rounded-lg border">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50">
        <tr class="text-left">
          <th class="px-3 py-2">ID</th>
          <th class="px-3 py-2">Tytuł</th>
          <th class="px-3 py-2">LIVE</th>
          <th class="px-3 py-2">Status</th>
          <th class="px-3 py-2">Uczestników</th>
          <th class="px-3 py-2">Dodano</th>
          <th class="px-3 py-2"></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($draws): ?>
          <?php foreach ($draws as $d): ?>
            <tr class="border-t hover:bg-gray-50">
              <td class="px-3 py-2"><?= (int)$d['id'] ?></td>
              <td class="px-3 py-2 font-medium"><?= h($d['title'] ?? '') ?></td>
              <td class="px-3 py-2"><?= h($d['live_title'] ?? '(offline)') ?></td>
              <td class="px-3 py-2">
                <?php
                $statusMap = [
                  'draft'  => 'bg-gray-100 text-gray-700',
                  'arming' => 'bg-amber-100 text-amber-800',
                  'frozen' => 'bg-sky-100 text-sky-800',
                  'drawn'  => 'bg-emerald-100 text-emerald-800',
                  'closed' => 'bg-red-100 text-red-800',
                ];
                $cls = $statusMap[$d['status']] ?? 'bg-gray-100 text-gray-700';
                ?>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold <?= $cls ?>">
                  <?= h($d['status']) ?>
                </span>
              </td>
              <td class="px-3 py-2 tabular-nums"><?= (int)($d['participants_count'] ?? 0) ?></td>
              <td class="px-3 py-2"><?= h((string)($d['created_at'] ?? '')) ?></td>
              <td class="px-3 py-2">
                <a class="text-blue-600 hover:underline" href="view.php?id=<?= (int)$d['id'] ?>">Podgląd →</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="7" class="px-3 py-8 text-center text-gray-500">Brak losowań w tym widoku</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginacja -->
  <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between mt-4 text-sm">
      <div>Rekordów: <strong><?= (int)$total ?></strong> • Strona <?= (int)$page ?> / <?= (int)$totalPages ?></div>
      <div class="flex gap-2">
        <?php
        // Helper do linków z zachowaniem filtrów
        $baseQs = $_GET;
        $mk = function (int $p) use ($baseQs) {
          $baseQs['page'] = $p;
          return '?' . http_build_query($baseQs);
        };
        ?>
        <a class="px-3 py-1 border rounded <?= $page <= 1 ? 'pointer-events-none opacity-50' : '' ?>" href="<?= $mk(max(1, $page - 1)) ?>">←</a>
        <a class="px-3 py-1 border rounded <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : '' ?>" href="<?= $mk(min($totalPages, $page + 1)) ?>">→</a>
      </div>
    </div>
  <?php endif; ?>

  <p class="mt-4">
    <a href="create.php" class="inline-flex items-center rounded px-3 py-2 border hover:bg-gray-50">➕ Utwórz nowe losowanie</a>
  </p>
</div>
<?php
require_once __DIR__ . '/../../layout/layout_footer.php';
