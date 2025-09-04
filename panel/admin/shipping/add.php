<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$page_title = "Dodaj metodę wysyłki";
require_once __DIR__ . '/../../layout/layout_header.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? '';
    $default_price = floatval($_POST['default_price'] ?? 0);
    $max_package_weight = floatval($_POST['max_package_weight'] ?? 0);
    $active = isset($_POST['active']) ? 1 : 0;

    if ($name && in_array($type, ['kurier', 'paczkomat', 'odbior_osobisty', 'broker', 'manual'])) {
        $stmt = $pdo->prepare("INSERT INTO shipping_methods (owner_id, name, type, default_price, max_package_weight, active)
            VALUES (:owner_id, :name, :type, :default_price, :max_package_weight, :active)");
        $stmt->execute([
            ':owner_id' => $owner_id,
            ':name' => $name,
            ':type' => $type,
            ':default_price' => $default_price,
            ':max_package_weight' => $max_package_weight,
            ':active' => $active,
        ]);
        $_SESSION['success_message'] = "Dodano nową metodę";
echo '<script>window.location.href = "index.php";</script>';
exit;

    } else {
        $error = "Uzupełnij poprawnie wszystkie pola.";
    }
}
?>

<div class="max-w-xl mx-auto p-4">
    <h1 class="text-2xl font-semibold mb-4">➕ Dodaj metodę wysyłki</h1>

    <?php if (!empty($error)): ?>
        <div class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4"><?= $error ?></div>
    <?php endif; ?>

    <form method="post" class="space-y-4">

        <div>
            <label class="block text-sm font-medium mb-1">Nazwa metody</label>
            <input type="text" name="name" class="w-full border border-gray-300 rounded px-3 py-2" required>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Typ wysyłki</label>
            <select name="type" class="w-full border border-gray-300 rounded px-3 py-2" required>
                <option value="">-- Wybierz --</option>
                <option value="kurier">Kurier</option>
                <option value="paczkomat">Paczkomat</option>
                <option value="odbior_osobisty">Odbiór osobisty</option>
                <option value="broker">Broker kurierski</option>
                <option value="manual">Manualna obsługa</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Cena domyślna (zł)</label>
            <input type="number" step="0.01" name="default_price" class="w-full border border-gray-300 rounded px-3 py-2" required>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Maksymalna waga paczki (kg)</label>
            <input type="number" step="0.01" name="max_package_weight" class="w-full border border-gray-300 rounded px-3 py-2" required>
        </div>

        <div class="flex items-center">
            <input type="checkbox" name="active" id="active" class="mr-2" checked>
            <label for="active" class="text-sm">Metoda aktywna</label>
        </div>

        <div class="flex space-x-2 pt-2">
            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded shadow">
                💾 Zapisz metodę
            </button>
            <a href="index.php" class="text-gray-600 hover:underline py-2">⏪ Anuluj</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
