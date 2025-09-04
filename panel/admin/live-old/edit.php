<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$stream_id = (int)($_GET['id'] ?? 0);

if ($stream_id <= 0) {
    die('Brak ID transmisji.');
}

// Obsługa zapisu zmian
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title    = trim($_POST['title'] ?? '');
    $platform = $_POST['platform'] ?? '';
    $url      = trim($_POST['stream_url'] ?? '');
    $status   = $_POST['status'] ?? '';

    $valid_platforms = ['facebook', 'youtube', 'tiktok'];
    $valid_statuses  = ['planned', 'live', 'ended'];

    if (
        $title === '' || $url === '' ||
        !in_array($platform, $valid_platforms, true) ||
        !in_array($status, $valid_statuses, true)
    ) {
        die('Nieprawidłowe dane.');
    }

    $stmt = $pdo->prepare("
        UPDATE live_streams
        SET title=?, platform=?, stream_url=?, status=?
        WHERE id=? AND owner_id=?
    ");
    $stmt->execute([$title, $platform, $url, $status, $stream_id, $owner_id]);

    header("Location: index.php");
    exit;
}

// Pobierz dane transmisji
$stmt = $pdo->prepare("SELECT * FROM live_streams WHERE id = ? AND owner_id = ?");
$stmt->execute([$stream_id, $owner_id]);
$stream = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$stream) {
    die('Transmisja nie istnieje.');
}

$platforms = ['facebook' => 'Facebook', 'youtube' => 'YouTube', 'tiktok' => 'TikTok'];
$statuses  = ['planned' => 'Zaplanowana', 'live' => 'Na żywo', 'ended' => 'Zakończona'];
?>

<a href="index.php" class="text-sm text-blue-600 hover:underline flex items-center gap-1 mb-6">
  <span class="text-lg">←</span> Wróć do listy transmisji
</a>

<div class="max-w-xl mx-auto space-y-6">
  <h1 class="text-2xl font-bold">✏️ Edycja transmisji</h1>

  <form method="post" class="bg-white shadow rounded-xl p-6 space-y-4">
    <div>
      <label class="block text-sm font-semibold mb-1">Tytuł</label>
      <input type="text" name="title" value="<?= htmlspecialchars($stream['title']) ?>" required
             class="w-full border rounded-lg p-2" />
    </div>

    <div>
      <label class="block text-sm font-semibold mb-1">Platforma</label>
      <select name="platform" class="w-full border rounded-lg p-2">
        <?php foreach ($platforms as $k => $v): ?>
          <option value="<?= $k ?>" <?= $stream['platform'] === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-sm font-semibold mb-1">Link do transmisji</label>
      <input type="url" name="stream_url" value="<?= htmlspecialchars($stream['stream_url']) ?>" required
             class="w-full border rounded-lg p-2" />
    </div>

    <div>
      <label class="block text-sm font-semibold mb-1">Status</label>
      <select name="status" class="w-full border rounded-lg p-2">
        <?php foreach ($statuses as $k => $v): ?>
          <option value="<?= $k ?>" <?= $stream['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
      💾 Zapisz zmiany
    </button>
  </form>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
