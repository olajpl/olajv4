<?php
// admin/settings/owner/features.php — Olaj.pl V4 (dostępność funkcji wg subskrypcji)
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../engine/Subscription/SubscriptionEngine.php';
require_once __DIR__ . '/../../../engine/Subscription/FeatureBadgeRenderer.php';
require_once __DIR__ . '/../../../engine/Enum/FeatureKey.php';

use Engine\Subscription\SubscriptionEngine;
use Engine\Subscription\FeatureBadgeRenderer;
use Engine\Enum\FeatureKey;

$user = $_SESSION['user'] ?? [];
$role = (string)($user['role'] ?? '');
$ownerId = (int)($user['owner_id'] ?? 0);

if (!in_array($role, ['admin', 'superadmin'], true)) {
    http_response_code(403);
    exit("❌ Brak dostępu.");
}
if ($ownerId <= 0) {
    http_response_code(400);
    exit("❌ Brak owner_id.");
}

// ── Inicjalizacja ───────────────────────────────
$engine = new SubscriptionEngine($pdo);
$renderer = new FeatureBadgeRenderer($pdo, $engine);

// ── Widok ───────────────────────────────────────
require_once __DIR__ . '/../../../layout/layout_header.php';
?>

<div class="max-w-3xl mx-auto py-10">
  <h1 class="text-2xl font-bold mb-6">🛡️ Dostępność funkcji (abonament)</h1>
  <p class="text-sm text-gray-600 mb-6">
    Poniżej znajduje się lista funkcji dostępnych w ramach Twojego planu subskrypcyjnego lub aktywowanych ręcznie.
  </p>

  <div class="bg-white rounded-xl border divide-y divide-gray-200 shadow">
    <?php foreach (FeatureKey::cases() as $feature): ?>
      <?= $renderer->render($ownerId, $feature) ?>
    <?php endforeach; ?>
  </div>

  <div class="mt-6 text-xs text-gray-400">
    💡 Wskazówka: funkcje oznaczone <code>🔒</code> są niedostępne w bieżącym planie. Skontaktuj się z nami, aby je aktywować.
  </div>
</div>

<?php require_once __DIR__ . '/../../../layout/layout_footer.php'; ?>
