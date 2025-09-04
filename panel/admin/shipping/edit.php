<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$page_title = "Edytuj metodę wysyłki";
require_once __DIR__ . '/../../layout/layout_header.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$id = (int)($_GET['id'] ?? 0);

// Pobierz dane metody
$stmt = $pdo->prepare("SELECT * FROM shipping_methods WHERE id = :id AND owner_id = :owner_id");
$stmt->execute([':id' => $id, ':owner_id' => $owner_id]);
$method = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$method) {
    die("Nie znaleziono metody wysyłki.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? '';
    $default_price = floatval($_POST['default_price'] ?? 0);
    $max_package_weight = floatval($_POST['max_package_weight'] ?? 0);
    $active = isset($_POST['active']) ? 1 : 0;

    if ($name && in_array($type, ['kurier', 'paczkomat', 'odbior_osobisty', 'broker', 'manual'])) {
        $stmt = $pdo->prepare("UPDATE shipping_methods SET name = :name, type = :type, default_price = :default_price, max_package_weight = :max_package_weight, active = :active WHERE id = :id AND owner_id = :owner_id");
        $stmt->execute([
            ':name' => $name,
            ':type' => $type,
            ':default_price' => $default_price,
            ':max_package_weight' => $max_package_weight,
            ':active' => $active,
            ':id' => $id,
            ':owner_id' => $owner_id
        ]);
        $_SESSION['success_message'] = "Zaktualizowano metodę wysyłki.";
        header('Location: index.php');
        exit;
    } else {
        $error = "Uzupełnij poprawnie wszystkie pola.";
    }
}
?>

<div class="max-w-xl mx-auto p-4">
    <h1 class="text-2xl font-semibold mb-4">✏️ Edytuj metodę wysyłki</h1>

    <?php if (!empty($error)): ?>
        <div class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4"><?= $error ?></div>
    <?php endif; ?>

    <form method="post" class="space-y-4">
        <div>
            <label class="block text-sm font-medium mb-1">Nazwa metody</label>
            <input type="text" name="name" class="w-full border border-gray-300 rounded px-3 py-2" value="<?= htmlspecialchars($method['name']) ?>" required>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1
