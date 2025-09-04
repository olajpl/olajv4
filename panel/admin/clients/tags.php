<?php
// admin/clients/tags.php — Zarządzanie tagami klientów
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/layout_header.php';

session_start();

// Ujednolicenie handlera DB
$pdo = $pdo ?? ($db ?? null);
if (!$pdo) {
  http_response_code(500);
  exit('Brak połączenia z bazą.');
}

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($owner_id <= 0) {
  http_response_code(403);
  exit('Brak owner_id');
}

// CSRF
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// Walidator koloru #RRGGBB
function isValidHexColor(string $hex): bool
{
  return (bool)preg_match('/^#[0-9A-Fa-f]{6}$/', $hex);
}

// Dodawanie nowego tagu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'], $_POST['color'])) {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(400);
    exit('Nieprawidłowy token bezpieczeństwa.');
  }

  $name  = trim((string)$_POST['name']);
  $color = strtoupper(trim((string)$_POST['color']));

  if ($name === '' || !isValidHexColor($color)) {
    $_SESSION['success_message'] = "Podaj nazwę oraz poprawny kolor (#RRGGBB).";
    header("Location: tags.php");
    exit;
  }

  // Unikalność w obrębie ownera
  $chk = $pdo->prepare("SELECT id FROM client_tags WHERE owner_id = ? AND name = ? LIMIT 1");
  $chk->execute([$owner_id, $name]);
  if ($chk->fetchColumn()) {
    $_SESSION['success_message'] = "Taki tag już istnieje.";
    header("Location: tags.php");
    exit;
  }

  $ins = $pdo->prepare("INSERT INTO client_tags (owner_id, name, color) VALUES (?, ?, ?)");
  $ins->execute([$owner_id, $name, $color]);

  $_SESSION['success_message'] = "Tag dodany.";
  header("Location: tags.php");
  exit;
}

// Usuwanie tagu (tylko swojego ownera)
if (isset($_GET['delete'])) {
  $tag_id = (int)$_GET['delete'];

  // upewnij się, że tag należy do ownera
  $own = $pdo->prepare("SELECT 1 FROM client_tags WHERE id = ? AND owner_id = ? LIMIT 1");
  $own->execute([$tag_id, $owner_id]);
  if ($own->fetchColumn()) {
    $pdo->prepare("DELETE FROM client_tag_links WHERE tag_id = ?")->execute([$tag_id]);
    $pdo->prepare("DELETE FROM client_tags WHERE id = ? AND owner_id = ?")->execute([$tag_id, $owner_id]);
    $_SESSION['success_message'] = "Tag usunięty.";
  } else {
    $_SESSION['success_message'] = "Nie znaleziono tagu u tego właściciela.";
  }
  header("Location: tags.php");
  exit;
}

// Pobierz wszystkie tagi
$stmt = $pdo->prepare("SELECT * FROM client_tags WHERE owner_id = ? ORDER BY name");
$stmt->execute([$owner_id]);
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="max-w-3xl mx-auto p-6">
  <div class="flex items-center justify-between mb-4">
    <a href="index.php" class="text-sm text-blue-600 hover:underline flex items-center gap-1">
      <span class="text-lg">←</span> Wróć
    </a>
    <h1 class="text-2xl font-bold">🏷️ Zarządzanie tagami klientów</h1>
    <div></div>
  </div>

  <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
      <?= htmlspecialchars($_SESSION['success_message']) ?>
    </div>
    <?php unset($_SESSION['success_message']); ?>
  <?php endif; ?>

  <form method="post" class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4 items-end" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <div>
      <label class="block text-sm font-medium mb-1">Nazwa tagu:</label>
      <input type="text" name="name" class="w-full border rounded px-2 py-1" required>
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Kolor (HEX):</label>
      <input type="color" name="color" class="w-16 h-10" value="#FF8A00" required>
    </div>
    <div>
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Dodaj tag</button>
    </div>
  </form>

  <table class="w-full table-auto border">
    <thead>
      <tr class="bg-gray-100">
        <th class="p-2 text-left">Nazwa</th>
        <th class="p-2 text-left">Kolor</th>
        <th class="p-2 text-right">Akcje</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tags as $tag): ?>
        <tr class="border-b">
          <td class="p-2"><?= htmlspecialchars($tag['name']) ?></td>
          <td class="p-2">
            <span class="inline-block px-3 py-1 rounded-full text-white text-xs" style="background-color: <?= htmlspecialchars($tag['color']) ?>;">
              <?= htmlspecialchars($tag['color']) ?>
            </span>
          </td>
          <td class="p-2 text-right">
            <a href="tags.php?delete=<?= (int)$tag['id'] ?>"
              onclick="return confirm('Czy na pewno chcesz usunąć ten tag?')"
              class="text-red-600 hover:underline">Usuń</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($tags)): ?>
        <tr>
          <td colspan="3" class="p-4 text-center text-gray-500">Brak tagów</td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>