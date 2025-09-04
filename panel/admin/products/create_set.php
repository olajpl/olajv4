<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $active = isset($_POST['active']) ? 1 : 0;
    $image_path = null;

    // Walidacja
    if ($name === '') {
        $errors[] = "Podaj nazwę zestawu.";
    }

    if (empty($errors)) {
        // Upload zdjęcia
        if (!empty($_FILES['image']['name'])) {
            $upload_dir = __DIR__ . '/../../uploads/sets/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $filename = uniqid('set_') . '.' . strtolower($ext);
            $target_path = $upload_dir . $filename;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
                $image_path = $filename;
            } else {
                $errors[] = "Błąd podczas przesyłania zdjęcia.";
            }
        }

        if (empty($errors)) {
            // Zapis do bazy
            $stmt = $pdo->prepare("
                INSERT INTO product_sets (owner_id, name, description, price, image_path, active)
                VALUES (:owner_id, :name, :description, :price, :image_path, :active)
            ");
            $stmt->execute([
                'owner_id' => $owner_id,
                'name' => $name,
                'description' => $description,
                'price' => $price,
                'image_path' => $image_path,
                'active' => $active
            ]);

            $new_id = $pdo->lastInsertId();
            $_SESSION['success_message'] = "Zestaw został utworzony.";

            // Redirect zanim cokolwiek się wyświetli
            header("Location: edit_set.php?id=" . $new_id);
            exit;
        }
    }
}

// dopiero teraz ładujemy header, gdy nie ma przekierowania
require_once __DIR__ . '/../../layout/layout_header.php';
?>

<div class="max-w-3xl mx-auto px-4 py-6">
    <h1 class="text-2xl font-bold mb-4">➕ Dodaj nowy zestaw</h1>

    <?php if (!empty($errors)): ?>
        <div class="bg-red-100 text-red-700 p-3 rounded mb-4">
            <?= implode('<br>', $errors) ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="space-y-4">
        <div>
            <label class="block font-medium mb-1">Nazwa zestawu</label>
            <input type="text" name="name" class="border rounded w-full px-3 py-2" required>
        </div>

        <div>
            <label class="block font-medium mb-1">Opis</label>
            <textarea name="description" rows="4" class="border rounded w-full px-3 py-2"></textarea>
        </div>

        <div>
            <label class="block font-medium mb-1">Cena</label>
            <input type="number" step="0.01" name="price" class="border rounded w-full px-3 py-2" required>
        </div>

        <div>
            <label class="block font-medium mb-1">Zdjęcie</label>
            <input type="file" name="image" accept="image/*" class="block">
        </div>

        <div class="flex items-center gap-2">
            <input type="checkbox" name="active" value="1" checked>
            <label>Widoczny w sklepie</label>
        </div>

        <div>
            <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded">
                💾 Zapisz zestaw
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>