<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/layout_header.php';
require_once __DIR__ . '/../../layout/top_panel.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;

$stmt = $pdo->prepare("SELECT id, name FROM products WHERE owner_id = :owner_id ORDER BY name ASC");
$stmt->execute(['owner_id' => $owner_id]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = $_POST['product_id'] ?? null;
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $price = (float) ($_POST['purchase_price'] ?? 0);
    $note = $_POST['note'] ?? '';

    if ($product_id && $quantity > 0) {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE products SET stock = stock + :quantity WHERE id = :id AND owner_id = :owner_id")
            ->execute(['quantity' => $quantity, 'id' => $product_id, 'owner_id' => $owner_id]);

        $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, purchase_price, note) VALUES (:product_id, 'przyjęcie', :quantity, :purchase_price, :note)")
            ->execute([
                'product_id' => $product_id,
                'quantity' => $quantity,
                'purchase_price' => $price,
                'note' => $note
            ]);

        $pdo->commit();

        $_SESSION['success_message'] = "Towar przyjęty na magazyn.";
        header("Location: stock_movements.php");
        exit;
    }
}
?>

<div class="container py-4">
    <h2 class="mb-4">➕ Przyjęcie towaru</h2>

    <form method="post">
        <div class="mb-3">
            <label class="form-label">Produkt</label>
            <select name="product_id" class="form-select" required>
                <option value="">-- wybierz --</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Ilość</label>
            <input type="number" name="quantity" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Cena zakupu (zł)</label>
            <input type="number" step="0.01" name="purchase_price" class="form-control">
        </div>

        <div class="mb-3">
            <label class="form-label">Uwagi (opcjonalnie)</label>
            <input type="text" name="note" class="form-control">
        </div>

        <button type="submit" class="btn btn-success">Zapisz przyjęcie</button>
        <a href="stock_movements.php" class="btn btn-secondary">Anuluj</a>
    </form>
</div>

<?php include __DIR__ . '/../../layout/layout_footer.php'; ?>