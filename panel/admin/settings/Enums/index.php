<?php
// admin/settings/Enums/index.php — Olaj.pl V4 (zarządzanie enum_values dla suadminów)
declare(strict_types=1);

use Engine\Settings\EnumHelper;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

require_once __DIR__ . '/../../../layout/layout_header.php';
require_once __DIR__ . '/../../../engine/Settings/EnumHelper.php';

$user = $_SESSION['user'] ?? [];

if (($user['role'] ?? '') !== 'superadmin') {
    echo "<p class='text-red-500'>Brak dostępu.</p>";
    exit;
}

$setKey = $_GET['set'] ?? 'owner_setting_type';
$enum = new EnumHelper($pdo);
$rows = $enum->listBySet($setKey);

$stmt = $pdo->query("SELECT DISTINCT set_key FROM enum_values ORDER BY set_key ASC");
$setKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="max-w-4xl mx-auto">
    <h1 class="text-2xl font-bold mb-6">🧩 Zarządzanie enum_values <code><?= htmlspecialchars($setKey) ?></code></h1>

    <form method="get" class="mb-4">
        <label for="set" class="font-semibold">Zbiór enum:</label>
        <select name="set" id="set" class="border px-2 py-1 rounded" onchange="this.form.submit()">
            <?php foreach ($setKeys as $sk): ?>
                <option value="<?= htmlspecialchars($sk) ?>" <?= $setKey === $sk ? 'selected' : '' ?>><?= htmlspecialchars($sk) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <table class="w-full border table-auto text-sm">
        <thead>
            <tr class="bg-gray-100">
                <th class="p-2">#</th>
                <th class="p-2">value_key</th>
                <th class="p-2">label</th>
                <th class="p-2">description</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $i => $row): ?>
                <tr class="border-t">
                    <td class="p-2 text-gray-500"><?= $i + 1 ?></td>
                    <td class="p-2 font-mono text-blue-700 font-semibold"><?= htmlspecialchars($row['value_key']) ?></td>
                    <td class="p-2"><?= htmlspecialchars($row['label']) ?></td>
                    <td class="p-2 text-gray-600 text-xs italic">
                        <?= nl2br(htmlspecialchars($row['description'] ?? '')) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="mt-6">
        <p class="text-xs text-gray-400">🔒 Edycja tylko SQL / migracja – UI do zapisu będzie osobno.</p>
    </div>
</div>

<?php require_once __DIR__ . '/../../../layout/layout_footer.php'; ?>