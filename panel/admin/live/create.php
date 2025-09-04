<?php
// 1. opis czynności lub funkcji
// Formularz tworzenia nowej transmisji LIVE + obsługa zapisu do bazy (POST)

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';


$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$errors = [];
$ok_id = null;

// 2. opis czynności: Helper do konwersji datetime-local → MySQL DATETIME (lub null)
function dtlocal_to_mysql(?string $v): ?string {
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    // format HTML: 2025-08-13T17:30
    return str_replace('T', ' ', $v) . ':00';
}

// 3. opis czynności: Obsługa POST (walidacja minimalistyczna + INSERT)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title     = trim($_POST['title'] ?? '');
    $platform  = $_POST['platform'] ?? 'facebook';
    $status    = $_POST['status'] ?? 'planned';
    $streamUrl = trim($_POST['stream_url'] ?? '');
    $fbPostId  = trim($_POST['fb_post_id'] ?? '');
    $igPostId  = trim($_POST['ig_post_id'] ?? '');
    $startedAt = dtlocal_to_mysql($_POST['started_at'] ?? null);
    $endedAt   = dtlocal_to_mysql($_POST['ended_at'] ?? null);

    if ($title === '')   { $errors[] = 'Podaj tytuł transmisji.'; }
    if (!in_array($platform, ['facebook','youtube','tiktok'], true)) { $errors[] = 'Nieprawidłowa platforma.'; }
    if (!in_array($status, ['planned','live','ended'], true))        { $errors[] = 'Nieprawidłowy status.'; }

    if (!$errors) {
        $sql = "INSERT INTO live_streams
                  (owner_id, title, platform, stream_url, status, started_at, ended_at, fb_post_id, ig_post_id)
                VALUES
                  (:owner_id, :title, :platform, :stream_url, :status, :started_at, :ended_at, :fb_post_id, :ig_post_id)";
        $st = $pdo->prepare($sql);
        $ok = $st->execute([
            ':owner_id'   => $owner_id,
            ':title'      => $title,
            ':platform'   => $platform,
            ':stream_url' => $streamUrl ?: null,
            ':status'     => $status,
            ':started_at' => $startedAt,
            ':ended_at'   => $endedAt,
            ':fb_post_id' => $fbPostId ?: null,
            ':ig_post_id' => $igPostId ?: null,
        ]);

        if ($ok) {
            $ok_id = (int)$pdo->lastInsertId();
            header("Location: view.php?id=" . $ok_id);
            exit;
        } else {
            $errors[] = 'Nie udało się zapisać transmisji.';
        }
    }
}
require_once __DIR__ . '/../../layout/layout_header.php';
?>

<div class="max-w-3xl mx-auto px-4 py-6">
  <a href="index.php" class="inline-block mb-6 text-blue-600 hover:underline">⬅️ Wróć do listy</a>
  <h1 class="text-2xl font-bold mb-4">➕ Nowa transmisja LIVE</h1>

  <?php if ($errors): ?>
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 text-red-700 p-3">
      <ul class="list-disc pl-5">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- 4. opis czynności: Formularz dopasowany do kolumn live_streams -->
  <form method="post" class="bg-white rounded-xl shadow p-4 space-y-4">
    <div>
      <label class="block text-sm text-gray-700">Tytuł *</label>
      <input type="text" name="title" required class="w-full border rounded p-2" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <div>
        <label class="block text-sm text-gray-700">Platforma *</label>
        <select name="platform" class="w-full border rounded p-2">
          <?php
          $platforms = ['facebook'=>'Facebook','youtube'=>'YouTube','tiktok'=>'TikTok'];
          $sel = $_POST['platform'] ?? 'facebook';
          foreach ($platforms as $k=>$v):
          ?>
            <option value="<?= $k ?>" <?= $sel===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm text-gray-700">Status *</label>
        <select name="status" class="w-full border rounded p-2">
          <?php
          $statuses = ['planned'=>'planned','live'=>'live','ended'=>'ended'];
          $ss = $_POST['status'] ?? 'planned';
          foreach ($statuses as $k=>$v):
          ?>
            <option value="<?= $k ?>" <?= $ss===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm text-gray-700">Link do streamu</label>
        <input type="url" name="stream_url" class="w-full border rounded p-2" placeholder="https://…" value="<?= htmlspecialchars($_POST['stream_url'] ?? '') ?>">
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="block text-sm text-gray-700">Start</label>
        <input type="datetime-local" name="started_at" class="w-full border rounded p-2" value="<?= htmlspecialchars($_POST['started_at'] ?? '') ?>">
      </div>
      <div>
        <label class="block text-sm text-gray-700">Koniec</label>
        <input type="datetime-local" name="ended_at" class="w-full border rounded p-2" value="<?= htmlspecialchars($_POST['ended_at'] ?? '') ?>">
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="block text-sm text-gray-700">FB Post ID</label>
        <input type="text" name="fb_post_id" class="w-full border rounded p-2" value="<?= htmlspecialchars($_POST['fb_post_id'] ?? '') ?>">
      </div>
      <div>
        <label class="block text-sm text-gray-700">IG Post ID</label>
        <input type="text" name="ig_post_id" class="w-full border rounded p-2" value="<?= htmlspecialchars($_POST['ig_post_id'] ?? '') ?>">
      </div>
    </div>

    <div class="pt-2">
      <button class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Zapisz</button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
