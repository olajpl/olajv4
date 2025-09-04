<?php
// Panel CW – Ustawienia automatycznych wiadomości (v1, zgodne z cw_events.enabled)
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/top_panel.php';
require_once __DIR__ . '/../../layout/layout_header.php';

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);

// Zapis zmian
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event'], $_POST['enabled'])) {
  $event = trim($_POST['event']);
  $enabled = (int)$_POST['enabled'];
  $stmt = $pdo->prepare("UPDATE cw_events SET enabled=? WHERE event=? AND owner_id=?");
  $stmt->execute([$enabled, $event, $owner_id]);
  
}

// Pobierz eventy
$stmt = $pdo->prepare("SELECT event, description, enabled FROM cw_events WHERE owner_id = ? ORDER BY event");
$stmt->execute([$owner_id]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="max-w-4xl mx-auto p-6">
  <h1 class="text-2xl font-semibold mb-4">CW – Ustawienia automatycznych wiadomości</h1>

  <?php if (isset($_GET['ok'])): ?>
    <div class="mb-4 bg-green-100 text-green-800 p-3 rounded">Zapisano zmianę ustawień</div>
  <?php endif; ?>

  <table class="min-w-full text-sm border rounded">
    <thead class="bg-gray-50 text-left">
      <tr>
        <th class="p-2">Zdarzenie</th>
        <th class="p-2">Opis</th>
        <th class="p-2">Aktywne</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($events as $e): ?>
        <tr class="border-t">
          <td class="p-2 font-mono"><?= htmlspecialchars($e['event']) ?></td>
          <td class="p-2"><?= htmlspecialchars($e['description'] ?? '') ?></td>
          <td class="p-2">
            <form method="post" class="inline">
              <input type="hidden" name="event" value="<?= htmlspecialchars($e['event']) ?>">
              <select name="enabled" onchange="this.form.submit()" class="border px-2 py-1 rounded">
                <option value="1" <?= $e['enabled'] ? 'selected' : '' ?>>Włączony</option>
                <option value="0" <?= !$e['enabled'] ? 'selected' : '' ?>>Wyłączony</option>
              </select>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
