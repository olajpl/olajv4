<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/layout_header.php';
require_once __DIR__ . '/../../layout/top_panel.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;

$stmt = $pdo->prepare("SELECT id, name, stock FROM products WHERE owner_id = :owner_id ORDER BY name ASC");
$stmt->execute(['owner_id' => $owner_id]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = $_POST['product_id'] ?? null;
    $new_stock = (int) ($_POST['new_stock'] ?? 0);
    $note = $_POST['note'] ?? '';

    $stmt = $pdo->prepare("SELECT stock FROM products WHERE id = :id AND owner_id = :owner_id");
    $stmt->execute(['id' => $product_id, 'owner_id' => $owner_id]);
    $current = $stmt->fetchColumn();

    if ($product_id && is_numeric($current)) {
        $difference = $new_stock - $current;

        $pdo->beginTransaction();

        $pdo->prepare("UPDATE products SET stock = :stock WHERE id = :id AND owner_id = :owner_id")
            ->execute(['stock' => $new_stock, 'id' => $product_id, 'owner_id' => $owner_id]);

        $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, purchase_price, note) VALUES (:product_id, 'korekta', :quantity, 0, :note)")
            ->execute([
                'product_id' => $product_id,
                'quantity' => $difference,
                'note' => $note
            ]);

        $pdo->commit();

        $_SESSION['success_message'] = "Stan magazynowy skorygowany.";
        header("Location: stock_movements.php");
        exit;
    }
}
?>

<div class="container py-4">
    <h2 class="mb-4">✍️ Korekta stanu magazynowego</h2>

    <form method="post">
        <div class="mb-3">
            <label class="form-label">Produkt</label>
            <select name="product_id" class="form-select" required>
                <option value="">-- wybierz --</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (aktualny: <?= $p['stock'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Nowy stan</label>
            <input type="number" name="new_stock" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Uwagi (opcjonalnie)</label>
            <input type="text" name="note" class="form-control">
        </div>

        <button type="submit" class="btn btn-success">Zapisz korektę</button>
        <a href="stock_movements.php" class="btn btn-secondary">Anuluj</a>
    </form>
</div>

<?php include __DIR__ . '/../../layout/layout_footer.php'; ?>
