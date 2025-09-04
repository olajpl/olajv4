<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/layout_header.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;

// Filtry
$status_filter = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');

// Budowa warunku WHERE
$where = "WHERE owner_id = :owner_id";
$params = ['owner_id' => $owner_id];

if ($status_filter === 'active') {
    $where .= " AND active = 1";
} elseif ($status_filter === 'inactive') {
    $where .= " AND active = 0";
}

if ($search !== '') {
    $where .= " AND name LIKE :search";
    $params['search'] = "%{$search}%";
}

// Pobierz zestawy
$stmt = $pdo->prepare("SELECT * FROM product_sets $where ORDER BY id DESC");
$stmt->execute($params);
$sets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h1 class="text-2xl font-bold">Zestawy produktów</h1>
        <a href="create_set.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded">➕ Dodaj nowy</a>
    </div>

    <!-- Filtry -->
    <form method="GET" class="flex gap-2 mb-4">
        <select name="status" class="border rounded px-3 py-2">
            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>Wszystkie</option>
            <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Aktywne</option>
            <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Nieaktywne</option>
        </select>
        <input type="text" name="search" placeholder="Szukaj..." value="<?= htmlspecialchars($search) ?>" class="border rounded px-3 py-2">
        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">Filtruj</button>
    </form>

    <!-- Lista -->
    <table class="min-w-full bg-white border">
        <thead>
            <tr class="bg-gray-100">
                <th class="px-4 py-2 border">Zdjęcie</th>
                <th class="px-4 py-2 border">Nazwa</th>
                <th class="px-4 py-2 border">Cena</th>
                <th class="px-4 py-2 border">Status</th>
                <th class="px-4 py-2 border">Akcje</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sets as $set): ?>
                <tr>
                    <td class="px-4 py-2 border">
                        <?php if (!empty($set['image_path'])): ?>
                            <img src="/uploads/sets/<?= htmlspecialchars($set['image_path']) ?>" alt="" class="w-16 h-16 object-cover">
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-2 border"><?= htmlspecialchars($set['name']) ?></td>
                    <td class="px-4 py-2 border"><?= number_format($set['price'], 2, ',', ' ') ?> zł</td>
                    <td class="px-4 py-2 border">
                        <?= $set['active'] ? '✅ Aktywny' : '❌ Nieaktywny' ?>
                    </td>
                    <td class="px-4 py-2 border">
                        <a href="edit_set.php?id=<?= $set['id'] ?>" class="text-blue-500 hover:underline">✏️ Edytuj</a> |
                        <a href="delete_set.php?id=<?= $set['id'] ?>" onclick="return confirm('Usunąć zestaw?')" class="text-red-500 hover:underline">🗑 Usuń</a> |
                        <a href="toggle_visibility.php?id=<?= $set['id'] ?>" class="text-gray-500 hover:underline">
                            <?= $set['active'] ? 'Wyłącz' : 'Włącz' ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>