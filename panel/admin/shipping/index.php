<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM shop_settings WHERE owner_id = ? LIMIT 1");
$stmt->execute([$owner_id]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

$page_title = "Metody wysyłki";
require_once __DIR__ . '/../../layout/layout_header.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;

// Obsługa usuwania
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM shipping_methods WHERE id = :id AND owner_id = :owner_id");
    $stmt->execute([':id' => $delete_id, ':owner_id' => $owner_id]);
    $_SESSION['success_message'] = "Metoda wysyłki została usunięta.";
    echo '<script>window.location.href = "index.php";</script>';
    exit;
}
if (!empty($settings['enable_math_riddle'])) {
    echo "<div class='p-4 bg-yellow-100 border border-yellow-300 rounded text-sm mb-4'>🧠 Zagadka matematyczna włączona przy zamykaniu paczki!</div>";
}
$stmt = $pdo->prepare("SELECT * FROM owners WHERE id = ? LIMIT 1");
$stmt->execute([$owner_id]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);
// Pobierz metody
$stmt = $pdo->prepare("SELECT * FROM shipping_methods WHERE owner_id = :owner_id ORDER BY name ASC");
$stmt->execute([':owner_id' => $owner_id]);
$methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="max-w-5xl mx-auto p-4">
    <h1 class="text-2xl font-semibold mb-4">🚚 Metody wysyłki</h1>

    <?php if (!empty($_SESSION['success_message'])): ?>
        <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
            <?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
        </div>
    <?php endif; ?>

    <div class="flex justify-between items-center mb-4">
        <a href="add.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded shadow text-sm">
            ➕ Dodaj metodę wysyłki
        </a>
    </div>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="min-w-full table-auto border-collapse text-sm">
            <thead class="bg-gray-100 text-gray-700">
                <tr>
                    <th class="px-4 py-2 text-left">Nazwa</th>
                    <th class="px-4 py-2 text-left">Typ</th>
                    <th class="px-4 py-2 text-left">Cena domyślna</th>
                    <th class="px-4 py-2 text-left">Max. waga</th>
                    <th class="px-4 py-2 text-left">Status</th>
                    <th class="px-4 py-2 text-right">Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($methods as $method): ?>
                    <tr class="border-t">
                        <td class="px-4 py-2"><?= htmlspecialchars($method['name']) ?></td>
<td class="px-4 py-2 capitalize"><?= $method['type'] ?></td>
<td class="px-4 py-2"><?= number_format((float)($method['default_price'] ?? 0), 2) ?> zł</td>
<td class="px-4 py-2"><?= number_format((float)($method['max_package_weight'] ?? 0), 2) ?> kg</td>
<td class="px-4 py-2">

                            <?php if ($method['active']): ?>
                                <span class="inline-block px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded">Aktywna</span>
                            <?php else: ?>
                                <span class="inline-block px-2 py-1 text-xs font-medium bg-red-100 text-red-700 rounded">Nieaktywna</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2 text-right space-x-2 whitespace-nowrap">
                            <a href="edit.php?id=<?= $method['id'] ?>" class="text-blue-600 hover:underline text-sm">✏️ Edytuj</a>
                            <a href="weight_rules.php?id=<?= $method['id'] ?>" class="text-gray-600 hover:underline text-sm">⚖️ Progi</a>
                            <a href="?delete=<?= $method['id'] ?>" class="text-red-600 hover:underline text-sm"
                               onclick="return confirm('Na pewno usunąć tę metodę wysyłki?')">🗑 Usuń</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($methods)): ?>
                    <tr>
                        <td colspan="6" class="px-4 py-4 text-center text-gray-500">Brak metod wysyłki.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<input type="checkbox" name="captcha_lock_enabled" value="1" <?= $settings['captcha_lock_enabled'] ? 'checked' : '' ?>>
<label>Włącz zagadkę matematyczną przy zamykaniu paczki</label>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
