<?php
// 1. opis czynności lub funkcji
// Lista transmisji LIVE dla zalogowanego ownera z licznikiem pozycji w live_temp

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/layout_header.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;

// 2. opis czynności: Pobranie transmisji + liczba pozycji w live_temp (niefinalizowanych)
$sql = "
  SELECT ls.id, ls.title, ls.platform, ls.stream_url, ls.status,
         ls.started_at, ls.ended_at, ls.created_at,
         ls.fb_post_id, ls.ig_post_id,
         COALESCE(p.pending_count, 0) AS pending_count
  FROM live_streams ls
  LEFT JOIN (
    SELECT live_id, COUNT(*) AS pending_count
    FROM live_temp
    WHERE transferred_at IS NULL
    GROUP BY live_id
  ) p ON p.live_id = ls.id
  WHERE ls.owner_id = ?
  ORDER BY ls.created_at DESC, ls.id DESC
";
$st = $pdo->prepare($sql);
$st->execute([$owner_id]);
$streams = $st->fetchAll(PDO::FETCH_ASSOC);

// 3. opis czynności: Mapa kolorów statusów (zgodnie z enum planned/live/ended)
$statusColor = [
  'planned' => 'bg-yellow-100 text-yellow-800',
  'live'    => 'bg-green-100 text-green-800',
  'ended'   => 'bg-gray-100 text-gray-800',
];
?>

<div class="max-w-7xl mx-auto px-4 py-6">
  <div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">🎥 Transmisje LIVE</h1>
    <a href="create.php" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
      ➕ Nowa transmisja
    </a>
  </div>

  <div class="bg-white rounded-xl shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-4 py-2 text-left">Tytuł</th>
          <th class="px-4 py-2">Platforma</th>
          <th class="px-4 py-2">Status</th>
          <th class="px-4 py-2">Pozycje</th>
          <th class="px-4 py-2">Start</th>
          <th class="px-4 py-2">Koniec</th>
          <th class="px-4 py-2">Utworzono</th>
          <th class="px-4 py-2"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($streams)): ?>
          <tr>
            <td colspan="8" class="px-4 py-6 text-center text-gray-400">Brak transmisji</td>
          </tr>
        <?php else: ?>
          <?php foreach ($streams as $s): ?>
            <tr>
              <td class="px-4 py-2">
                <div class="font-medium"><?= htmlspecialchars($s['title'] ?? '—') ?></div>
                <?php if (!empty($s['stream_url'])): ?>
                  <a href="<?= htmlspecialchars($s['stream_url']) ?>" target="_blank"
                     class="text-xs text-blue-600 hover:underline">🔗 link do streamu</a>
                <?php endif; ?>
              </td>
              <td class="px-4 py-2 capitalize"><?= htmlspecialchars($s['platform'] ?? '—') ?></td>
              <td class="px-4 py-2">
                <?php $cls = $statusColor[$s['status']] ?? 'bg-gray-100 text-gray-800'; ?>
                <span class="px-2 py-1 rounded text-xs <?= $cls ?>">
                  <?= htmlspecialchars($s['status'] ?? '—') ?>
                </span>
              </td>
              <td class="px-4 py-2">
                <span class="inline-flex items-center px-2 py-1 rounded bg-gray-100">
                  <?= (int)$s['pending_count'] ?>
                </span>
              </td>
              <td class="px-4 py-2"><?= $s['started_at'] ?: '—' ?></td>
              <td class="px-4 py-2"><?= $s['ended_at'] ?: '—' ?></td>
              <td class="px-4 py-2"><?= $s['created_at'] ?: '—' ?></td>
              <td class="px-4 py-2 text-right">
                <div class="inline-flex gap-2">
                  <a href="view.php?id=<?= (int)$s['id'] ?>"
                     class="px-3 py-1 bg-indigo-600 text-white rounded hover:bg-indigo-700">▶️ Otwórz</a>
                  <?php if (!empty($s['fb_post_id'])): ?>
                    <span title="FB post id: <?= htmlspecialchars($s['fb_post_id']) ?>" class="px-2 py-1 text-xs bg-blue-50 text-blue-700 rounded">FB</span>
                  <?php endif; ?>
                  <?php if (!empty($s['ig_post_id'])): ?>
                    <span title="IG post id: <?= htmlspecialchars($s['ig_post_id']) ?>" class="px-2 py-1 text-xs bg-pink-50 text-pink-700 rounded">IG</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
