<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Pobranie filtrów
$decision = $_GET['decision'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Budowa zapytania
$query = "SELECT cal.*, c.name AS client_name, o.id AS order_id
    FROM checkout_access_log cal
    LEFT JOIN order_groups og ON cal.order_group_id = og.id
    LEFT JOIN orders o ON og.order_id = o.id
    LEFT JOIN clients c ON o.client_id = c.id
    WHERE 1=1";
$params = [];

if ($decision) {
    $query .= " AND cal.decision = :decision";
    $params['decision'] = $decision;
}
if ($date_from) {
    $query .= " AND cal.created_at >= :date_from";
    $params['date_from'] = $date_from . " 00:00:00";
}
if ($date_to) {
    $query .= " AND cal.created_at <= :date_to";
    $params['date_to'] = $date_to . " 23:59:59";
}

$query .= " ORDER BY cal.created_at DESC LIMIT 200";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pl">

<head>
    <meta charset="UTF-8">
    <title>Logi dostępu do checkoutu</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 p-6">
    <div class="max-w-7xl mx-auto bg-white p-6 rounded shadow">
        <h1 class="text-2xl font-bold mb-4">📜 Logi dostępu do checkoutu</h1>

        <!-- Filtry -->
        <form method="get" class="flex flex-wrap gap-4 mb-6">
            <select name="decision" class="border rounded p-2">
                <option value="">Wszystkie decyzje</option>
                <option value="allow" <?= $decision === 'allow' ? 'selected' : '' ?>>Allow (zezwolono)</option>
                <option value="block" <?= $decision === 'block' ? 'selected' : '' ?>>Block (zablokowano)</option>
            </select>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="border rounded p-2">
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="border rounded p-2">
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Filtruj</button>
        </form>

        <!-- Tabela logów -->
        <div class="overflow-x-auto">
            <table class="min-w-full border border-gray-200 text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="border p-2">Data</th>
                        <th class="border p-2">Decyzja</th>
                        <th class="border p-2">PGZ</th>
                        <th class="border p-2">Klient</th>
                        <th class="border p-2">Status paczki</th>
                        <th class="border p-2">Status płatności</th>
                        <th class="border p-2">Plik</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="border p-2"><?= htmlspecialchars($log['created_at']) ?></td>
                            <td class="border p-2 font-bold <?= $log['decision'] === 'allow' ? 'text-green-600' : 'text-red-600' ?>">
                                <?= htmlspecialchars($log['decision']) ?>
                            </td>
                            <td class="border p-2"><?= (int)$log['order_group_id'] ?></td>
                            <td class="border p-2"><?= htmlspecialchars($log['client_name'] ?? '-') ?></td>
                            <td class="border p-2"><?= htmlspecialchars($log['group_status']) ?></td>
                            <td class="border p-2"><?= htmlspecialchars($log['payment_status']) ?></td>
                            <td class="border p-2"><?= htmlspecialchars($log['script_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="7" class="text-center p-4 text-gray-500">Brak wpisów do wyświetlenia</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>

</html>