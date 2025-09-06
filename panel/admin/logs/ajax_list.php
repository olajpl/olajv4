<?php
// admin/logs/ajax_list.php
declare(strict_types=1);
require_once __DIR__.'/../../../bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = 50;
$offset  = ($page - 1) * $limit;

$from    = trim((string)($_GET['from'] ?? ''));
$to      = trim((string)($_GET['to'] ?? ''));
$level   = trim((string)($_GET['level'] ?? ''));
$channel = trim((string)($_GET['channel'] ?? ''));
$request = trim((string)($_GET['request_id'] ?? ''));
$q       = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];

// Jeśli masz multi-tenant i chcesz ograniczać do ownera:
if ($ownerId > 0) {
  $where[] = 'owner_id = :oid';
  $params[':oid'] = $ownerId;
}

if ($from !== '') {
  $where[] = 'created_at >= :from';
  $params[':from'] = $from . ' 00:00:00';
}
if ($to   !== '') {
  $where[] = 'created_at <= :to';
  $params[':to']   = $to   . ' 23:59:59';
}
if ($level !== '') {
  $where[] = 'level = :level';
  $params[':level'] = $level;
}
if ($channel !== '') {
  $where[] = 'channel LIKE :chan';
  $params[':chan']  = $channel . '%';
}
if ($request !== '') {
  $where[] = 'request_id = :rid';
  $params[':rid']   = $request;
}
if ($q !== '') {
  $where[] = '(message LIKE :q OR event LIKE :q OR channel LIKE :q)';
  $params[':q'] = '%' . $q . '%';
}

$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// licznik
$st = $pdo->prepare("SELECT COUNT(*) FROM logs $sqlWhere");
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $limit));

// dane
$sql = "SELECT id, owner_id, level, channel, event, message, request_id, created_at
        FROM logs
        $sqlWhere
        ORDER BY id DESC
        LIMIT :lim OFFSET :off";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':lim', $limit, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// render
?>
<div class="overflow-x-auto">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 text-gray-600">
      <tr>
        <th class="px-3 py-2 text-left">#</th>
        <th class="px-3 py-2 text-left">czas</th>
        <th class="px-3 py-2 text-left">level</th>
        <th class="px-3 py-2 text-left">channel</th>
        <th class="px-3 py-2 text-left">event</th>
        <th class="px-3 py-2 text-left">message</th>
        <th class="px-3 py-2 text-left">request_id</th>
        <th class="px-3 py-2"></th>
      </tr>
    </thead>
    <tbody class="divide-y">
      <?php if (!$rows): ?>
        <tr>
          <td colspan="8" class="px-3 py-6 text-center text-gray-500">Brak wpisów</td>
        </tr>
        <?php else: foreach ($rows as $r): ?>
          <tr class="hover:bg-gray-50">
            <td class="px-3 py-2"><?= (int)$r['id'] ?></td>
            <td class="px-3 py-2 whitespace-nowrap text-gray-600"><?= htmlspecialchars($r['created_at']) ?></td>
            <td class="px-3 py-2">
              <span class="inline-flex items-center px-2 py-0.5 rounded text-xs
              <?= $r['level'] === 'error' ? 'bg-red-100 text-red-700' : ($r['level'] === 'warning' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700') ?>">
                <?= htmlspecialchars($r['level']) ?>
              </span>
            </td>
            <td class="px-3 py-2"><?= htmlspecialchars($r['channel']) ?></td>
            <td class="px-3 py-2"><?= htmlspecialchars((string)$r['event']) ?></td>
            <td class="px-3 py-2 max-w-[420px] truncate" title="<?= htmlspecialchars((string)$r['message']) ?>">
              <?= htmlspecialchars((string)$r['message']) ?>
            </td>
            <td class="px-3 py-2 font-mono text-xs"><?= htmlspecialchars((string)$r['request_id']) ?></td>
            <td class="px-3 py-2 text-right">
              <button class="px-2 py-1 text-indigo-600 hover:underline" onclick="showLogDetails(<?= (int)$r['id'] ?>)">Szczegóły</button>
            </td>
          </tr>
      <?php endforeach;
      endif; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
  <div class="flex items-center justify-between p-3 text-sm">
    <div>Łącznie: <b><?= $total ?></b>, stron: <b><?= $pages ?></b></div>
    <div class="space-x-1">
      <?php
      $cur = $page;
      $start = max(1, $cur - 2);
      $end   = min($pages, $cur + 2);
      if ($cur > 1) echo '<button class="px-2 py-1 border rounded" onclick="pageGo(' . ($cur - 1) . ')">‹</button>';
      for ($i = $start; $i <= $end; $i++) {
        $cls = $i === $cur ? 'bg-indigo-600 text-white' : 'bg-white';
        echo '<button class="px-2 py-1 border rounded ' . $cls . '" onclick="pageGo(' . $i . ')">' . $i . '</button>';
      }
      if ($cur < $pages) echo '<button class="px-2 py-1 border rounded" onclick="pageGo(' . ($cur + 1) . ')">›</button>';
      ?>
    </div>
  </div>
<?php endif; ?>