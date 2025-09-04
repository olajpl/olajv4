<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$set_id = (int)($_GET['id'] ?? 0);

if ($set_id <= 0) {
    die("Błędne ID zestawu");
}

// Pobierz dane zestawu
$stmt = $pdo->prepare("SELECT * FROM product_sets WHERE id = :id AND owner_id = :owner_id");
$stmt->execute(['id' => $set_id, 'owner_id' => $owner_id]);
$set = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$set) {
    die("Zestaw nie istnieje");
}

// ====== AKCJE ======

// Aktualizacja danych zestawu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_set'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $price = (float)$_POST['price'];
    $active = isset($_POST['active']) ? 1 : 0;

    // Upload zdjęcia
    $image_path = $set['image_path'];
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
        }
    }

    $stmt = $pdo->prepare("
        UPDATE product_sets 
        SET name = :name, description = :description, price = :price, active = :active, image_path = :image_path 
        WHERE id = :id AND owner_id = :owner_id
    ");
    $stmt->execute([
        'name' => $name,
        'description' => $description,
        'price' => $price,
        'active' => $active,
        'image_path' => $image_path,
        'id' => $set_id,
        'owner_id' => $owner_id
    ]);

    $_SESSION['success_message'] = "Zestaw zaktualizowany.";
    header("Location: edit_set.php?id=" . $set_id);
    exit;
}

// Dodawanie produktu do zestawu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $product_id = (int)$_POST['product_id'];
    $quantity = (int)$_POST['quantity'];

    $stmt = $pdo->prepare("
        INSERT INTO product_set_items (set_id, product_id, quantity) 
        VALUES (:set_id, :product_id, :quantity)
    ");
    $stmt->execute([
        'set_id' => $set_id,
        'product_id' => $product_id,
        'quantity' => $quantity
    ]);

    $_SESSION['success_message'] = "Produkt dodany do zestawu.";
    header("Location: edit_set.php?id=" . $set_id);
    exit;
}

// Usuwanie produktu z zestawu
if (isset($_GET['remove_item'])) {
    $item_id = (int)$_GET['remove_item'];
    $stmt = $pdo->prepare("DELETE FROM product_set_items WHERE id = :id AND set_id = :set_id");
    $stmt->execute(['id' => $item_id, 'set_id' => $set_id]);

    $_SESSION['success_message'] = "Produkt usunięty z zestawu.";
    header("Location: edit_set.php?id=" . $set_id);
    exit;
}

// ====== POBIERANIE DANYCH DO WIDOKU ======

// Produkty w zestawie
$stmt = $pdo->prepare("
    SELECT psi.id, psi.quantity, p.name, p.price 
    FROM product_set_items psi
    JOIN products p ON psi.product_id = p.id
    WHERE psi.set_id = :set_id
");
$stmt->execute(['set_id' => $set_id]);
$set_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Produkty z magazynu
$stmt = $pdo->prepare("SELECT id, name, price FROM products WHERE owner_id = :owner_id ORDER BY name ASC");
$stmt->execute(['owner_id' => $owner_id]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ====== WIDOK ======
require_once __DIR__ . '/../../layout/layout_header.php';
?>

<div class="max-w-5xl mx-auto px-4 py-6">
    <h1 class="text-2xl font-bold mb-4">✏️ Edytuj zestaw: <?= htmlspecialchars($set['name']) ?></h1>

    <!-- Formularz edycji zestawu -->
    <form method="POST" enctype="multipart/form-data" class="mb-6">
        <input type="hidden" name="update_set" value="1">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label>Nazwa</label>
                <input type="text" name="name" value="<?= htmlspecialchars($set['name']) ?>" class="border rounded w-full px-3 py-2" required>
            </div>
            <div>
                <label>Cena</label>
                <input type="number" step="0.01" name="price" value="<?= htmlspecialchars($set['price']) ?>" class="border rounded w-full px-3 py-2" required>
            </div>
        </div>

        <div>
            <label>Opis</label>
            <textarea name="description" class="border rounded w-full px-3 py-2"><?= htmlspecialchars($set['description']) ?></textarea>
        </div>

        <div>
            <label>Zdjęcie</label><br>
            <?php if ($set['image_path']): ?>
                <img src="/uploads/sets/<?= htmlspecialchars($set['image_path']) ?>" alt="" class="w-32 h-32 object-cover mb-2">
            <?php endif; ?>
            <input type="file" name="image" accept="image/*">
        </div>

        <div class="flex items-center gap-2">
            <input type="checkbox" name="active" value="1" <?= $set['active'] ? 'checked' : '' ?>>
            <label>Widoczny w sklepie</label>
        </div>

        <div>
            <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded">💾 Zapisz</button>
        </div>
    </form>

    <hr class="my-6">

    <!-- Produkty w zestawie -->
    <h2 class="text-xl font-bold mb-2">📦 Produkty w zestawie</h2>

    <form method="POST" class="flex gap-2 mb-4">
        <input type="hidden" name="add_product" value="1">
        <select name="product_id" class="border rounded px-3 py-2" required>
            <option value="">-- Wybierz produkt --</option>
            <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= number_format($p['price'], 2, ',', ' ') ?> zł)</option>
            <?php endforeach; ?>
        </select>
        <input type="number" name="quantity" value="1" min="1" class="border rounded px-3 py-2 w-20" required>
        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">➕ Dodaj</button>
    </form>

    <?php if ($set_items): ?>
        <table class="min-w-full bg-white border">
            <thead>
                <tr class="bg-gray-100">
                    <th class="px-4 py-2 border">Produkt</th>
                    <th class="px-4 py-2 border">Cena</th>
                    <th class="px-4 py-2 border">Ilość</th>
                    <th class="px-4 py-2 border">Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($set_items as $item): ?>
                    <tr>
                        <td class="px-4 py-2 border"><?= htmlspecialchars($item['name']) ?></td>
                        <td class="px-4 py-2 border"><?= number_format($item['price'], 2, ',', ' ') ?> zł</td>
                        <td class="px-4 py-2 border"><?= $item['quantity'] ?></td>
                        <td class="px-4 py-2 border">
                            <a href="edit_set.php?id=<?= $set_id ?>&remove_item=<?= $item['id'] ?>"
                                onclick="return confirm('Usunąć produkt z zestawu?')"
                                class="text-red-500 hover:underline">🗑 Usuń</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>Brak produktów w tym zestawie.</p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>