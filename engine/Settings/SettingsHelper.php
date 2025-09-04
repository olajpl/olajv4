<?php
// engine/Settings/SettingsHelper.php — Olaj.pl V4
declare(strict_types=1);

namespace Engine\Settings;

use PDO;
use Throwable;
use Engine\Enum\SettingKey;

final class SettingsHelper
{
    /** 🧠 Cache na czas requestu */
    private static array $cache = [];

    /** Wyczyść cache ustawień (np. po zapisie) */
    public static function clear(): void
    {
        self::$cache = [];
    }

    /** value (string) lub null */
    public static function getString(PDO $pdo, int $ownerId, SettingKey $key): ?string
    {
        $row = self::load($pdo, $ownerId, $key);
        return isset($row['value']) ? (string)$row['value'] : null;
    }

    /** value (int) lub null */
    public static function getInt(PDO $pdo, int $ownerId, SettingKey $key): ?int
    {
        $row = self::load($pdo, $ownerId, $key);
        return isset($row['value']) ? (int)$row['value'] : null;
    }

    /** value_json (array) lub [] */
    public static function getArray(PDO $pdo, int $ownerId, SettingKey $key): array
    {
        $row = self::load($pdo, $ownerId, $key);
        if (!isset($row['value_json'])) return [];
        $arr = json_decode((string)$row['value_json'], true);
        return is_array($arr) ? $arr : [];
    }

    /** value (bool) lub false */
    public static function getBool(PDO $pdo, int $ownerId, SettingKey $key): bool
    {
        $val = self::getString($pdo, $ownerId, $key);
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    /** value_json (array) lub null */
    public static function getJson(PDO $pdo, int $ownerId, SettingKey $key): mixed
    {
        $row = self::load($pdo, $ownerId, $key);
        if (!isset($row['value_json'])) return null;
        return json_decode((string)$row['value_json'], true);
    }

    /* ───────────────────── INTERNAL ───────────────────── */

    private static function load(PDO $pdo, int $ownerId, SettingKey $key): ?array
    {
        $k = $key->value;
        $cacheKey = "{$ownerId}:{$k}";

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $st = $pdo->prepare("SELECT `value`, `value_json` FROM owner_settings WHERE owner_id = :oid AND `key` = :k LIMIT 1");
            $st->execute([':oid' => $ownerId, ':k' => $k]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            self::$cache[$cacheKey] = $row ?: null;
            return $row ?: null;
        } catch (Throwable $__) {
            return null;
        }
    }
    /** Zwraca wartość lub domyślny fallback */
    public static function getOrDefault(PDO $pdo, int $ownerId, SettingKey $key, mixed $fallback): mixed
    {
        $row = self::load($pdo, $ownerId, $key);
        if (isset($row['value'])) return $row['value'];
        if (isset($row['value_json'])) {
            $decoded = json_decode((string)$row['value_json'], true);
            return is_array($decoded) || is_scalar($decoded) ? $decoded : $fallback;
        }
        return $fallback;
    }

    /** Czy istnieje ustawienie (value lub value_json)? */
    public static function has(PDO $pdo, int $ownerId, SettingKey $key): bool
    {
        $row = self::load($pdo, $ownerId, $key);
        return isset($row['value']) || isset($row['value_json']);
    }
}
