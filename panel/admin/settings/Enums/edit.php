<?php

// admin/settings/Enums/edit.php — Olaj.pl V4 (dodawanie/edycja enum_values)
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

require_once __DIR__ . '/../../../layout/layout_header.php';

$user = $_SESSION['user'] ?? [];

if (($user['role'] ?? '') !== 'superadmin') {
    echo "<p class='text-red-500'>Brak dostępu.</p>";
    exit;
}

$editing = isset($_GET['id']);
$row = [
    'set_key' => $_GET['set'] ?? 'owner_setting_type',
    'value_key' => '',
    'label' => '',
    'description' => '',
    'sort_order' => 0,
];

if ($editing) {
    $stmt = $pdo->prepare("SELECT * FROM enum_values WHERE id = ? LIMIT 1");
    $stmt->execute([$_GET['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $row = array_merge($row, $_POST);

    if ($editing) {
        $stmt = $pdo->prepare("UPDATE enum_values SET label = ?, description = ?, sort_order = ? WHERE id = ?");
        $stmt->execute([$row['label'], $row['description'], (int)$row['sort_order'], $_GET['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO enum_values (set_key, value_key, label, description, sort_order)
                           VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $row['set_key'],
            $row['value_key'],
            $row['label'],
            $row['description'],
            (int)$row['sort_order']
        ]);
    }
    header("Location: index.php?set=" . urlencode($row['set_key']));
    exit;
}
?>
<div class="max-w-xl mx-auto">
    <h1 class="text-2xl font-bold mb-4">
        <?= $editing ? '✏️ Edytuj enum' : '➕ Dodaj nowy enum' ?> <code><?= htmlspecialchars($row['set_key']) ?></code>
    </h1>

    <form method="post" class="space-y-4">
        <?php if (!$editing): ?>
            <div>
                <label class="block font-semibold">Wartość (value_key):</label>
                <input type="text" name="value_key" required class="input w-full" value="<?= htmlspecialchars($row['value_key']) ?>">
            </div>
        <?php endif; ?>

        <div>
            <label class="block font-semibold">Etykieta (label):</label>
            <input type="text" name="label" required class="input w-full" value="<?= htmlspecialchars($row['label']) ?>">
        </div>

        <div>
            <label class="block font-semibold">Opis (description):</label>
            <textarea name="description" rows="3" class="input w-full"><?= htmlspecialchars($row['description']) ?></textarea>
        </div>

        <div>
            <label class="block font-semibold">Kolejność (sort_order):</label>
            <input type="number" name="sort_order" class="input w-full" value="<?= (int)$row['sort_order'] ?>">
        </div>

        <div class="flex gap-4 items-center">
            <button type="submit" class="btn btn-primary">💾 Zapisz</button>
            <a href="index.php?set=<?= urlencode($row['set_key']) ?>" class="text-gray-600 text-sm">↩ Wróć do listy</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../../layout/layout_footer.php'; ?>