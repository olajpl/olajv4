<?php
// engine/Settings/FeatureGate.php
declare(strict_types=1);

namespace Engine\Settings;

use PDO;
use Engine\Enum\SettingKey;
use Engine\Enum\PlanTier;
use Throwable;

/**
 * FeatureGate – silnik SaaS do zarządzania dostępnością ficzerów i limitów per-owner.
 * - Odczytuje plan właściciela (`plan.tier`)
 * - Odczytuje nadpisania flag (`feature.flags`)
 * - Odczytuje nadpisania limitów (`feature.limits`)
 */
final class FeatureGate
{
    /** Zwraca plan (free|basic|pro|ultra), domyślnie 'basic' */
    public static function getPlan(PDO $pdo, int $ownerId): PlanTier
    {
        $tier = self::getSettingValue($pdo, $ownerId, SettingKey::PLAN_TIER->value);
        $t = is_string($tier) ? strtolower($tier) : 'basic';
        return match ($t) {
            'free'  => PlanTier::FREE,
            'pro'   => PlanTier::PRO,
            'ultra' => PlanTier::ULTRA,
            default => PlanTier::BASIC,
        };
    }

    /**
     * Czy ficzer jest włączony – priorytet:
     *   1. Nadpisanie w feature.flags (value_json)
     *   2. Domyślna wartość planu
     */
    public static function isEnabled(PDO $pdo, int $ownerId, string $featureKey): bool
    {
        $plan  = self::getPlan($pdo, $ownerId);
        $flags = $plan->defaultFlags();

        $over  = self::getSettingJson($pdo, $ownerId, SettingKey::FEATURE_FLAGS->value);
        if (is_array($over) && array_key_exists($featureKey, $over) && is_bool($over[$featureKey])) {
            return $over[$featureKey];
        }
        return (bool)($flags[$featureKey] ?? false);
    }

    /**
     * Zwraca limit (np. liczba, string) – priorytet:
     *   1. Nadpisanie w feature.limits (value_json)
     *   2. Domyślny limit planu
     */
    public static function getLimit(PDO $pdo, int $ownerId, string $limitKey): mixed
    {
        $plan   = self::getPlan($pdo, $ownerId);
        $limits = $plan->defaultLimits();

        $over = self::getSettingJson($pdo, $ownerId, SettingKey::FEATURE_LIMITS->value);
        if (is_array($over) && array_key_exists($limitKey, $over)) {
            return $over[$limitKey];
        }
        return $limits[$limitKey] ?? null;
    }

    /* ===== Low-level helpers ===== */

    /** value_json (array) lub null */
    private static function getSettingJson(PDO $pdo, int $ownerId, string $key): ?array
    {
        try {
            $st = $pdo->prepare("SELECT value_json FROM owner_settings WHERE owner_id = :oid AND `key` = :k LIMIT 1");
            $st->execute([':oid' => $ownerId, ':k' => $key]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || !isset($row['value_json'])) return null;
            $arr = json_decode((string)$row['value_json'], true);
            return is_array($arr) ? $arr : null;
        } catch (Throwable $__) {
            return null;
        }
    }

    /** value (string) lub null */
    private static function getSettingValue(PDO $pdo, int $ownerId, string $key): ?string
    {
        try {
            $st = $pdo->prepare("SELECT value FROM owner_settings WHERE owner_id = :oid AND `key` = :k LIMIT 1");
            $st->execute([':oid' => $ownerId, ':k' => $key]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row['value'] ?? null;
        } catch (Throwable $__) {
            return null;
        }
    }
}
