<?php
// admin/settings/Subscriptions/index.php — Olaj.pl V4 (suadmin only)
declare(strict_types=1);

use Engine\Subscription\SubscriptionEngine;
use Engine\Enum\FeatureKey;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_once __DIR__ . '/../../../layout/layout_header.php';
require_once __DIR__ . '/../../../engine/Subscription/SubscriptionEngine.php';
require_once __DIR__ . '/../../../engine/Enum/FeatureKey.php';

$user = $_SESSION['user'] ?? [];

if (($user['role'] ?? '') !== 'superadmin') {
    echo "<p class='text-red-500'>Brak dostępu.</p>";
    exit;
}

$subscriptionEngine = new SubscriptionEngine($pdo);

$stmt = $pdo->query("SELECT o.id, o.name, s.key_name AS plan_key
                      FROM owners o
                 LEFT JOIN owner_subscriptions os ON os.owner_id = o.id AND os.status = 'active'
                 LEFT JOIN subscription_plans s ON s.id = os.plan_id
                 ORDER BY o.id ASC");
$owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<div class="max-w-4xl mx-auto">
    <h1 class="text-2xl font-bold mb-6">📦 Zarządzanie subskrypcjami</h1>

    <table class="w-full table-auto border">
        <thead>
            <tr class="bg-gray-100 text-left">
                <th class="p-2">ID</th>
                <th class="p-2">Właściciel</th>
                <th class="p-2">Plan</th>
                <th class="p-2">Aktywne funkcje</th>
                <th class="p-2"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($owners as $owner):
                $features = $subscriptionEngine->listEnabled((int)$owner['id']);
            ?>
                <tr class="border-b">
                    <td class="p-2 text-gray-600 text-sm"><?= (int)$owner['id'] ?></td>
                    <td class="p-2 font-semibold"><?= htmlspecialchars($owner['name']) ?></td>
                    <td class="p-2 text-blue-700 font-bold uppercase"><?= $owner['plan_key'] ?? 'brak' ?></td>
                    <td class="p-2 text-sm text-gray-700">
                        <?php foreach ($features as $feat): ?>
                            <span class="inline-block bg-green-100 text-green-800 px-2 py-0.5 rounded text-xs mr-1 mb-1"><?= $feat ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td class="p-2">
                        <a href="subscription_edit.php?owner_id=<?= (int)$owner['id'] ?>" class="text-blue-600 underline text-sm">Edytuj</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../../layout/layout_footer.php'; ?>