<?php
// engine/Subscription/FeatureBadgeRenderer.php — Olaj.pl V4 (UI: oznaczenia funkcji wg planu i override)
declare(strict_types=1);

namespace Engine\Subscription;

use Engine\Enum\FeatureKey;
use PDO;

final class FeatureBadgeRenderer
{
    public function __construct(private PDO $pdo, private SubscriptionEngine $engine) {}

    /**
     * Zwraca HTML z odznaką i opisem funkcji
     */
    public function render(int $ownerId, FeatureKey $feature): string
    {
        $has = $this->engine->has($ownerId, $feature);
        $plan = $this->getFeaturePlan($feature);

        $label = $feature->label();
        $planLabel = strtoupper($plan ?? 'UNKNOWN');
        $badge = '';

        if ($has) {
            $badge = "<span class=\"inline-flex items-center text-xs font-semibold text-green-700 bg-green-100 px-2 py-1 rounded\">$planLabel ✅</span>";
        } else {
            $badge = "<span class=\"inline-flex items-center text-xs font-semibold text-gray-600 bg-gray-200 px-2 py-1 rounded\">$planLabel 🔒</span>";
        }

        return <<<HTML
<div class="flex items-center justify-between py-2 border-b border-gray-200">
  <div>
    <strong>{$label}</strong><br>
    <span class="text-sm text-gray-500">Moduł: {$feature->module()}</span>
  </div>
  <div>$badge</div>
</div>
HTML;
    }

    /**
     * Pobiera plan domyślny, który zawiera daną funkcję
     */
    private function getFeaturePlan(FeatureKey $feature): ?string
    {
        $sql = "SELECT p.key_name FROM subscription_plan_features spf
                  JOIN subscription_plans p ON p.id = spf.plan_id
                  JOIN subscription_features f ON f.id = spf.feature_id
                 WHERE f.key_name = ?
                 ORDER BY p.id ASC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$feature->value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['key_name'] ?? null;
    }
}
