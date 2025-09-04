<?php
// admin/settings/owner_settings/index.php — Olaj.pl V4: edytor ustawień właściciela (czytelny jak wół)
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../layout/layout_header.php';

$user = $_SESSION['user'] ?? [];
$ownerId = (int)($user['owner_id'] ?? 0);
$role = (string)($user['role'] ?? '');

if ($ownerId <= 0 || !in_array($role, ['admin', 'superadmin'], true)) {
    http_response_code(403);
    echo "<p class='text-red-600 p-4'>Brak dostępu.</p>";
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

// ─────────────────────────────────────── PRG: SAVE ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        http_response_code(400);
        echo "❌ Błąd CSRF.";
        exit;
    }

    $key   = trim((string)($_POST['key'] ?? ''));
    $type  = trim((string)($_POST['type'] ?? 'string'));
    $value = $_POST['value'] ?? '';
    $note  = trim((string)($_POST['note'] ?? ''));

    if ($key === '') {
        $_SESSION['flash'] = "❌ Brak klucza ustawienia.";
        header("Location: index.php");
        exit;
    }

    // Walidacja JSON
    if ($type === 'json' && $value !== '') {
        json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $_SESSION['flash'] = "❌ Błąd JSON: " . json_last_error_msg();
            header("Location: index.php");
            exit;
        }
    }

    // Wstaw lub aktualizuj
    $stmt = $pdo->prepare("INSERT INTO owner_settings (owner_id, `key`, `value`, `value_json`, `type`, `note`, updated_at)
                           VALUES (:owner_id, :key, :value, :value_json, :type, :note, NOW())
                           ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `value_json` = VALUES(`value_json`), `type` = VALUES(`type`), `note` = VALUES(`note`), updated_at = NOW()");

    $stmt->execute([
        'owner_id'   => $ownerId,
        'key'        => $key,
        'value'      => in_array($type, ['string','int','float','bool','text']) ? (string)$value : null,
        'value_json' => $type === 'json' ? $value : null,
        'type'       => $type,
        'note'       => $note,
    ]);

    $_SESSION['flash'] = "✅ Zapisano ustawienie „<strong>" . htmlspecialchars($key) . "</strong>”.";
    header("Location: index.php");
    exit;
}

// ─────────────────────────────────────── SELECT ───────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM owner_settings WHERE owner_id = ? ORDER BY `key` ASC");
$stmt->execute([$ownerId]);
$settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>

<div class="max-w-5xl mx-auto pb-24">
  <h1 class="text-2xl font-bold mb-4">⚙️ Ustawienia właściciela (owner_settings)</h1>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded bg-green-50 border border-green-200 text-green-700">
      <?= $flash ?>
    </div>
  <?php endif; ?>

  <form method="post" class="bg-white border rounded-lg p-4 mb-8 space-y-4">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <h2 class="text-lg font-semibold mb-2">➕ Dodaj / edytuj ustawienie</h2>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div>
        <label class="block text-sm font-semibold mb-1">🔑 Klucz</label>
        <input type="text" name="key" required class="w-full border px-3 py-1.5 rounded text-sm">
      </div>

      <div>
        <label class="block text-sm font-semibold mb-1">📦 Typ</label>
        <select name="type" class="w-full border px-2 py-1.5 rounded text-sm">
          <option value="string">string</option>
          <option value="int">int</option>
          <option value="float">float</option>
          <option value="bool">bool</option>
          <option value="text">text</option>
          <option value="json">json</option>
        </select>
      </div>

      <div class="md:col-span-2">
        <label class="block text-sm font-semibold mb-1">✏️ Wartość</label>
        <input type="text" name="value" class="w-full border px-3 py-1.5 rounded text-sm font-mono">
      </div>
    </div>

    <div>
      <label class="block text-sm font-semibold mb-1">💬 Notatka</label>
      <input type="text" name="note" class="w-full border px-3 py-1.5 rounded text-sm">
    </div>

    <div class="text-right pt-2">
      <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded hover:bg-blue-700">💾 Zapisz</button>
    </div>
  </form>

  <h2 class="text-lg font-semibold mb-2">📋 Lista ustawień</h2>

  <div class="overflow-x-auto">
    <table class="table-auto w-full border border-gray-300 text-sm">
      <thead class="bg-gray-100 text-left">
        <tr>
          <th class="p-2">Klucz</th>
          <th class="p-2">Typ</th>
          <th class="p-2">Wartość</th>
          <th class="p-2">Notatka</th>
          <th class="p-2">Zmieniono</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($settings as $row): ?>
          <tr class="border-t">
            <td class="p-2 font-mono text-blue-700"><?= htmlspecialchars($row['key']) ?></td>
            <td class="p-2"><?= htmlspecialchars($row['type']) ?></td>
            <td class="p-2 font-mono text-gray-700 whitespace-pre-wrap">
              <?php
                if ($row['type'] === 'json') {
                    echo '<code>' . htmlspecialchars(trim($row['value_json'] ?? '')) . '</code>';
                } else {
                    echo htmlspecialchars(trim((string)($row['value'] ?? '')));
                }
              ?>
            </td>
            <td class="p-2 text-gray-600"><?= htmlspecialchars($row['note'] ?? '') ?></td>
            <td class="p-2 text-gray-500 font-mono"><?= htmlspecialchars($row['updated_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../../layout/layout_footer.php'; ?>
