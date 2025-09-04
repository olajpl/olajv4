<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$page_title = "Progi wagowe wysyłki";
require_once __DIR__ . '/../../layout/layout_header.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$shipping_method_id = (int)($_GET['id'] ?? 0);

// Pobierz metodę
$stmt = $pdo->prepare("SELECT * FROM shipping_methods WHERE id = :id AND owner_id = :owner_id");
$stmt->execute([':id' => $shipping_method_id, ':owner_id' => $owner_id]);
$method = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$method) die("Nie znaleziono metody.");

// Dodawanie progu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    $min = floatval($_POST['min_weight'] ?? 0);
    $max = floatval($_POST['max_weight'] ?? 0);
    $price = floatval($_POST['price'] ?? 0);
    if ($min >= 0 && $max > $min && $price >= 0) {
        $pdo->prepare("INSERT INTO shipping_weight_rules (shipping_method_id, min_weight, max_weight, price)
            VALUES (:smid, :min, :max, :price)")->execute([
            ':smid' => $shipping_method_id,
            ':min' => $min,
            ':max' => $max,
            ':price' => $price
        ]);
        $_SESSION['success_message'] = "Dodano próg.";
        header("Location: weight_rules.php?id=" . $shipping_method_id); exit;
    } else {
        $error = "Nieprawidłowe dane.";
    }
}

// Usuwanie
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM shipping_weight_rules WHERE id = :id AND shipping_method_id = :smid")
        ->execute([':id' => (int)$_GET['delete'], ':smid' => $shipping_method_id]);
    $_SESSION['success_message'] = "Usunięto próg.";
    header("Location: weight_rules.php?id=" . $shipping_method_id); exit;
}

$rules = $pdo->prepare("SELECT * FROM shipping_weight_rules WHERE shipping_method_id = :id ORDER BY min_weight ASC");
$rules->execute([':id' => $shipping_method_id]);
$rules = $rules->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="max-w-4xl mx-auto p-4">
    <h1 class="text-2xl font-semibold mb-4">📦 Progi wagowe – <?= htmlspecialchars($method['name']) ?></h1>

    <?php if (!empty($_SESSION['success_message'])): ?>
        <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
            <?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="bg-red-100 text-red-800 px-4 py-2 rounded mb-4"><?= $error ?></div>
    <?php endif; ?>

    <form method="post" class="grid md:grid-cols-4 gap-4 mb-6 items-end">
        <input type="hidden" name="add" value="1">
        <div>
            <label class="block text-sm font-medium mb-1">Od (kg)</label>
            <input type="number" step="0.01" name="min_weight" class="w-full rounded border border-gray-300 px-3 py-2" required>
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Do (kg)</label>
            <input type="number" step="0.01" name="max_weight" class="w-full rounded border border-gray-300 px-3 py-2" required>
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Cena (zł)</label>
            <input type="number" step="0.01" name="price" class="w-full rounded border border-gray-300 px-3 py-2" required>
        </div>
        <div>
            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded w-full">
                ➕ Dodaj próg
            </button>
        </div>
    </form>

    <div class="bg-white shadow-sm rounded-lg overflow-hidden">
        <table class="min-w-full table-auto border-collapse">
            <thead class="bg-gray-100">
                <tr class="text-left text-sm text-gray-700">
                    <th class="px-4 py-2">Od (kg)</th>
                    <th class="px-4 py-2">Do (kg)</th>
                    <th class="px-4 py-2">Cena (zł)</th>
                    <th class="px-4 py-2 text-right">Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $rule): ?>
                    <tr class="border-t">
                        <td class="px-4 py-2"><?= number_format($rule['min_weight'], 2) ?></td>
                        <td class="px-4 py-2"><?= number_format($rule['max_weight'], 2) ?></td>
                        <td class="px-4 py-2"><?= number_format($rule['price'], 2) ?> zł</td>
                        <td class="px-4 py-2 text-right">
                            <a href="?id=<?= $shipping_method_id ?>&delete=<?= $rule['id'] ?>"
                               onclick="return confirm('Usunąć ten próg?')"
                               class="text-red-600 hover:underline text-sm">🗑 Usuń</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rules)): ?>
                    <tr><td colspan="4" class="px-4 py-4 text-center text-gray-500">Brak progów wagowych.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        <a href="index.php" class="text-blue-600 hover:underline text-sm">⏪ Wróć do metod wysyłki</a>
    </div>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
