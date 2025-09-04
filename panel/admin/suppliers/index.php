<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM suppliers WHERE owner_id = ? ORDER BY name ASC");
$stmt->execute([$owner_id]);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Dostawcy";
require_once __DIR__ . '/../../layout/layout_header.php';
?>

<div class="p-6">
  <h1 class="text-2xl font-bold mb-6">📋 Lista dostawców</h1>

  <div class="mb-6 text-right">
    <a href="create.php" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">➕ Dodaj dostawcę</a>
  </div>

  <div class="bg-white shadow rounded border overflow-x-auto">
    <table class="min-w-full table-auto">
      <thead class="bg-gray-100 text-sm">
        <tr>
          <th class="px-4 py-2 text-left">Nazwa</th>
          <th class="px-4 py-2 text-left">Email</th>
          <th class="px-4 py-2 text-left">Telefon</th>
          <th class="px-4 py-2 text-left">Adres</th>
          <th class="px-4 py-2 text-left">Box</th>
          <th class="px-4 py-2"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($suppliers as $s): ?>
          <tr class="border-t">
            <td class="px-4 py-2 text-sm"><?= htmlspecialchars($s['name'] ?? '') ?></td>
            <td class="px-4 py-2 text-sm"><?= htmlspecialchars($s['email'] ?? '') ?></td>
            <td class="px-4 py-2 text-sm"><?= htmlspecialchars($s['phone'] ?? '') ?></td>
            <td class="px-4 py-2 text-sm"><?= htmlspecialchars($s['address'] ?? '') ?></td>
            <td class="px-4 py-2 text-sm"><?= htmlspecialchars($s['box'] ?? '') ?></td>
            <td class="px-4 py-2 text-right whitespace-nowrap">
              <a href="view.php?id=<?= $s['id'] ?>" class="text-gray-700 hover:underline mr-3">Podgląd</a>
              <a href="edit.php?id=<?= $s['id'] ?>" class="text-blue-600 hover:underline mr-3">Edytuj</a>
              <a href="delete.php?id=<?= $s['id'] ?>" class="text-red-600 hover:underline" onclick="return confirm('Na pewno usunąć dostawcę?')">Usuń</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>