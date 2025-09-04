<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/layout_header.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;

// Statystyki
// Poprawione statystyki transmisji LIVE
$sql = "
  SELECT
    COALESCE(SUM(oi.price), 0) AS total_sales,
    COALESCE(SUM(oi.quantity), 0) AS total_qty
  FROM order_items oi
  JOIN order_groups og ON og.id = oi.order_group_id
  JOIN orders o ON o.id = og.order_id
  WHERE oi.source = 'live' AND o.owner_id = ?
";
$st = $pdo->prepare($sql);
$st->execute([$owner_id]);
$row = $st->fetch(PDO::FETCH_ASSOC) ?: ['total_sales' => 0, 'total_qty' => 0];

$total_sales = number_format((float)$row['total_sales'], 2, ',', ' ') . ' zł';
$total_qty = (int)$row['total_qty'] . ' szt.';




// Lista transmisji
$stmt = $pdo->prepare("SELECT * FROM live_streams WHERE owner_id = ? ORDER BY created_at DESC");
$stmt->execute([$owner_id]);
$streams = $stmt->fetchAll(PDO::FETCH_ASSOC);

$platforms = ['facebook' => 'Facebook', 'youtube' => 'YouTube', 'tiktok' => 'TikTok'];

function status_pill(string $status): string {
  $map = [
    'planned' => 'bg-gray-200 text-gray-800',
    'live'    => 'bg-green-200 text-green-800',
    'ended'   => 'bg-red-200 text-red-800',
  ];
  $cls = $map[$status] ?? 'bg-gray-200 text-gray-800';
  return "<span class=\"text-xs font-semibold px-2 py-1 rounded-full {$cls}\">" . strtoupper($status) . "</span>";
}
?>

<a href="../" class="text-sm text-blue-600 hover:underline flex items-center gap-1 mb-6">
  <span class="text-lg">←</span> Wróć
</a>

<div class="max-w-7xl mx-auto px-4 space-y-10">

  <!-- 📊 Statystyki -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="bg-white shadow rounded-xl p-4">
      <div class="text-sm text-gray-500 mb-1">💰 Suma sprzedaży (LIVE)</div>
      <div class="text-xl font-bold"><?= $total_sales ?></div>
    </div>
    <div class="bg-white shadow rounded-xl p-4">
      <div class="text-sm text-gray-500 mb-1">📦 Produkty sprzedane</div>
      <div class="text-xl font-bold"><?= $total_qty ?></div>
    </div>
    <div class="bg-white shadow rounded-xl p-4">
      <div class="text-sm text-gray-500 mb-1">🎥 Liczba transmisji</div>
      <div class="text-xl font-bold"><?= $stream_count ?></div>
    </div>
  </div>

  <!-- ➕ Formularz dodania transmisji -->
  <form method="post" action="save_stream.php" class="bg-white rounded-xl shadow p-4 flex flex-col md:flex-row items-stretch gap-4">
    <input type="text" name="title" placeholder="Tytuł transmisji" required
           class="flex-1 border rounded-lg p-2 w-full" />

    <select name="platform" required class="flex-1 border rounded-lg p-2 bg-white">
      <option value="">-- Platforma --</option>
      <?php foreach ($platforms as $key => $name): ?>
        <option value="<?= $key ?>"><?= $name ?></option>
      <?php endforeach; ?>
    </select>

    <input type="url" name="stream_url" placeholder="Link do transmisji" required
           class="flex-1 border rounded-lg p-2 w-full" />

    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 text-sm font-semibold">
      ➕ Dodaj
    </button>
  </form>

  <!-- 📋 Lista transmisji -->
  <div class="bg-white shadow rounded-xl overflow-x-auto">
    <table class="w-full table-auto text-sm">
      <thead class="bg-gray-100">
        <tr>
          <th class="text-left p-3">Tytuł</th>
          <th class="text-left p-3">Platforma</th>
          <th class="text-left p-3">Status</th>
          <th class="text-left p-3">Data</th>
          <th class="text-left p-3">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($streams as $s): ?>
          <tr class="border-t hover:bg-gray-50">
            <td class="p-3 font-medium"><?= htmlspecialchars($s['title']) ?></td>
            <td class="p-3"><?= status_pill($s['status'] ?? 'unknown') ?>
</td>
            <td class="p-3"><?= status_pill($s['status']) ?></td>
            <td class="p-3"><?= $s['created_at'] ?></td>
            <td class="p-3 space-x-2">
              <a href="view.php?id=<?= (int)$s['id'] ?>" class="text-green-600 hover:underline">▶️ Otwórz</a>
              <a href="edit.php?id=<?= (int)$s['id'] ?>" class="text-blue-600 hover:underline">✏️ Edytuj</a>
              <a href="delete_stream.php?id=<?= (int)$s['id'] ?>"
                 onclick="return confirm('Czy na pewno usunąć transmisję?');"
                 class="text-red-600 hover:underline">❌ Usuń</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
