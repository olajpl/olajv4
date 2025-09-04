<?php
// admin/products/tags.php – Zarządzanie tagami produktów
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;

// ✅ Logika dodawania tagu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
  $name = trim($_POST['name']);
  $color = $_POST['color'] ?? '#999999';
  if ($name) {
    $stmt = $pdo->prepare("INSERT INTO product_tags (name, color, owner_id) VALUES (?, ?, ?)");
    $stmt->execute([$name, $color, $owner_id]);
    $_SESSION['success_message'] = "✅ Tag został dodany.";
    header("Location: tags.php");
    exit;
  }
}

// ✅ Logika usuwania tagu
if (isset($_GET['delete'])) {
  $id = (int) $_GET['delete'];
  $stmt = $pdo->prepare("DELETE FROM product_tags WHERE id = ? AND owner_id = ?");
  $stmt->execute([$id, $owner_id]);
  $_SESSION['success_message'] = "🗑️ Tag został usunięty.";
  header("Location: tags.php");
  exit;
}

// ✅ Dopiero po obsłudze POST/GET ładujemy layout
require_once __DIR__ . '/../../layout/layout_header.php';

// Pobieranie tagów
$stmt = $pdo->prepare("SELECT * FROM product_tags WHERE owner_id = ? ORDER BY name ASC");
$stmt->execute([$owner_id]);
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="p-6 max-w-3xl mx-auto">
  <h1 class="text-2xl font-bold mb-4">🏷️ Tagi produktów</h1>

  <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="mb-4 bg-green-100 border-l-4 border-green-500 text-green-800 p-4">
      <?= $_SESSION['success_message'] ?>
      <?php unset($_SESSION['success_message']); ?>
    </div>
  <?php endif; ?>

  <form method="post" class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
    <input type="text" name="name" class="p-2 border rounded" placeholder="Nazwa tagu" required>
    <input type="color" name="color" class="p-2 border rounded" value="#999999">
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">➕ Dodaj tag</button>
  </form>

  <table class="w-full border-collapse">
    <thead>
      <tr class="bg-gray-100">
        <th class="text-left px-4 py-2">Nazwa</th>
        <th class="text-left px-4 py-2">Kolor</th>
        <th class="text-right px-4 py-2">Akcje</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tags as $tag): ?>
        <tr class="border-t">
          <td class="px-4 py-2"><?= htmlspecialchars($tag['name']) ?></td>
          <td class="px-4 py-2">
            <span class="inline-block w-6 h-6 rounded" style="background-color: <?= htmlspecialchars($tag['color']) ?>;"></span>
            <code class="text-sm ml-2"><?= $tag['color'] ?></code>
          </td>
          <td class="px-4 py-2 text-right">
            <a href="?delete=<?= $tag['id'] ?>" onclick="return confirm('Na pewno usunąć tag?')" class="text-red-600 hover:underline">🗑 Usuń</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
