<?php
// engine/Subscription/SubscriptionEngine.php — Olaj.pl V4 (abonamenty + dostępność)
declare(strict_types=1);

namespace Engine\Subscription;

use PDO;
use PDOException;
use RuntimeException;
use Engine\Enum\FeatureKey;

final class SubscriptionEngine
{
    public function __construct(private PDO $pdo) {}

    /**
     * Sprawdza, czy właściciel ma dostęp do funkcji.
     */
    public function has(int $ownerId, FeatureKey $feature): bool
    {
        try {
            // Najpierw override (czy jawnie włączone/wyłączone)
            $sql = "SELECT is_enabled FROM owner_feature_overrides WHERE owner_id = ? AND feature_id = (
                        SELECT id FROM subscription_features WHERE key_name = ?
                    ) AND (valid_until IS NULL OR valid_until > NOW())";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$ownerId, $feature->value]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return (bool)$row['is_enabled'];
            }

            // Jeśli brak override → sprawdź czy plan zawiera tę funkcję
            $sql = "SELECT COUNT(*) FROM owner_subscriptions os
                      JOIN subscription_plan_features spf ON spf.plan_id = os.plan_id
                      JOIN subscription_features f ON f.id = spf.feature_id
                     WHERE os.owner_id = ?
                       AND os.status IN ('active', 'trial')
                       AND f.key_name = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$ownerId, $feature->value]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            // Opcjonalnie logg() lub error fallback
            return false;
        }
    }

    /**
     * Wymusza dostępność funkcji — rzuca wyjątek jeśli brak dostępu.
     */
    public function require(int $ownerId, FeatureKey $feature): void
    {
        if (!$this->has($ownerId, $feature)) {
            throw new RuntimeException("Brak dostępu do funkcji: {$feature->label()}");
        }
    }

    /**
     * Zwraca listę aktywnych funkcji (dla panelu / sklepu).
     */
    public function listEnabled(int $ownerId): array
    {
        $features = [];

        // 1. Z override'ów
        $sql = "SELECT f.key_name FROM owner_feature_overrides o
                  JOIN subscription_features f ON f.id = o.feature_id
                 WHERE o.owner_id = ? AND o.is_enabled = 1
                   AND (o.valid_until IS NULL OR o.valid_until > NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$ownerId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $features[] = $row['key_name'];
        }

        // 2. Z planu (jeśli nie nadpisano)
        $sql = "SELECT f.key_name FROM owner_subscriptions os
                  JOIN subscription_plan_features spf ON spf.plan_id = os.plan_id
                  JOIN subscription_features f ON f.id = spf.feature_id
                 WHERE os.owner_id = ?
                   AND os.status IN ('active', 'trial')
                   AND f.key_name NOT IN (
                       SELECT f2.key_name FROM owner_feature_overrides o2
                         JOIN subscription_features f2 ON f2.id = o2.feature_id
                        WHERE o2.owner_id = ?
                   )";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$ownerId, $ownerId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $features[] = $row['key_name'];
        }

        return array_unique($features);
    }

    /**
     * Loguje użycie funkcji — do billingów, limitów, itp.
     */
    public function trackUsage(int $ownerId, FeatureKey $feature, float $qty = 1.0, ?string $context = null, ?int $contextId = null): void
    {
        try {
            $sql = "INSERT INTO feature_usage (owner_id, feature_id, qty, context, context_id)
                    VALUES (?, (SELECT id FROM subscription_features WHERE key_name = ?), ?, ?, ?)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$ownerId, $feature->value, $qty, $context, $contextId]);
        } catch (PDOException $e) {
            // logg('error', 'subscription.trackUsage', $e->getMessage());
        }
    }

    /**
     * Opcjonalny cache do `owners.subscription_cached_json`
     */
    public function updateCache(int $ownerId): void
    {
        $features = $this->listEnabled($ownerId);
        $json = json_encode($features, JSON_UNESCAPED_UNICODE);

        $sql = "UPDATE owners SET subscription_cached_json = ? WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$json, $ownerId]);
    }
}
