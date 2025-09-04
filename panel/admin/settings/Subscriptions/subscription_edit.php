<?php
// admin/settings/Subscriptions/subscription_edit.php — edytor subskrypcji Olaj.pl V4
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

require_once __DIR__ . '/../../../layout/layout_header.php';
require_once __DIR__ . '/../../../engine/Settings/OwnerSettings.php';

use Engine\Settings\OwnerSettings;

$user = $_SESSION['user'] ?? [];

if (($user['role'] ?? '') !== 'superadmin') {
    echo "<p class='text-red-500'>Brak dostępu.</p>";
    exit;
}


$ownerId = isset($_GET['owner_id']) ? (int)$_GET['owner_id'] : 0;
if (!$ownerId) {
    echo "<p class='text-red-500'>Brak ID właściciela.</p>";
    exit;
}

// Lista dostępnych ficzerów
$allFeatures = [
    'ai' => 'AI generator opisów',
    'live' => 'Moduł LIVE',
    'cw' => 'Centralny Wysyłacz (CW)',
    'shipping_integrations' => 'Integracje dostawców',
];

// Obsługa POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plan = $_POST['subscription_plan'] ?? 'basic';
    $features = $_POST['features'] ?? [];

    // Zapis planu
    OwnerSettings::set($pdo, $ownerId, 'subscription.plan', $plan, 'string');

    // Zapis feature'ów (true/false)
    foreach ($allFeatures as $key => $label) {
        $enabled = isset($features[$key]);
        OwnerSettings::set($pdo, $ownerId, "subscription.feature.$key", $enabled, 'bool');
    }

    header("Location: subscription_edit.php?owner_id=$ownerId&ok=1");
    exit;
}

// Pobierz dane właściciela
$stmt = $pdo->prepare("SELECT id, name, email FROM owners WHERE id = ?");
$stmt->execute([$ownerId]);
$owner = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$owner) {
    echo "<p class='text-red-500'>Nie znaleziono właściciela.</p>";
    exit;
}

// Pobierz aktualne ustawienia
$settings = OwnerSettings::getAll($pdo, $ownerId);
$currentPlan = $settings['subscription.plan'] ?? 'basic';
$currentFeatures = [];
foreach ($allFeatures as $key => $_) {
    $currentFeatures[$key] = !empty($settings["subscription.feature.$key"]);
}
?>

<div class="max-w-3xl mx-auto mt-8">
    <h1 class="text-2xl font-bold mb-4">🧩 Edycja subskrypcji: <?= htmlspecialchars($owner['email']) ?> (ID: <?= $ownerId ?>)</h1>

    <?php if (isset($_GET['ok'])): ?>
        <div class="mb-4 p-3 bg-green-100 text-green-800 border border-green-300 rounded">✅ Zapisano zmiany.</div>
    <?php endif; ?>

    <form method="post" class="space-y-6">
        <div>
            <label class="block font-semibold mb-1">Plan abonamentowy:</label>
            <select name="subscription_plan" class="input w-full max-w-xs">
                <option value="basic" <?= $currentPlan === 'basic' ? 'selected' : '' ?>>Basic</option>
                <option value="pro" <?= $currentPlan === 'pro' ? 'selected' : '' ?>>Pro</option>
                <option value="ultra" <?= $currentPlan === 'ultra' ? 'selected' : '' ?>>Ultra</option>
            </select>
        </div>

        <div>
            <label class="block font-semibold mb-2">Dodatkowe funkcje (manual override):</label>
            <div class="space-y-2">
                <?php foreach ($allFeatures as $key => $label): ?>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="features[<?= $key ?>]" value="1"
                            <?= !empty($currentFeatures[$key]) ? 'checked' : '' ?>
                            class="toggle toggle-primary" />
                        <span><?= htmlspecialchars($label) ?> <code>(<?= $key ?>)</code></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="pt-4 flex gap-4">
            <button type="submit" class="btn btn-primary">💾 Zapisz ustawienia</button>
            <a href="index.php" class="btn btn-secondary">↩ Powrót</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../../layout/layout_footer.php'; ?>