<?php
// engine/Settings/OwnerSettings.php — Olaj.pl V4 (centralne ustawienia właściciela — wersja statyczna)
declare(strict_types=1);

namespace Engine\Settings;

use PDO;
use RuntimeException;

final class OwnerSettings
{
    public static function getString(PDO $pdo, int $ownerId, string $key, ?string $default = null): ?string
    {
        return self::getRaw($pdo, $ownerId, $key, 'string', $default);
    }

    public static function getBool(PDO $pdo, int $ownerId, string $key, bool $default = false): bool
    {
        $val = self::getRaw($pdo, $ownerId, $key, 'bool', $default ? '1' : '0');
        return $val === '1';
    }

    public static function getInt(PDO $pdo, int $ownerId, string $key, int $default = 0): int
    {
        return (int)self::getRaw($pdo, $ownerId, $key, 'int', (string)$default);
    }

    public static function getFloat(PDO $pdo, int $ownerId, string $key, float $default = 0.0): float
    {
        return (float)self::getRaw($pdo, $ownerId, $key, 'float', (string)$default);
    }

    public static function getJson(PDO $pdo, int $ownerId, string $key, mixed $default = []): mixed
    {
        $stmt = $pdo->prepare("SELECT value_json FROM owner_settings WHERE owner_id = ? AND `key` = ? AND type = 'json'");
        $stmt->execute([$ownerId, $key]);
        $json = $stmt->fetchColumn();
        return $json !== false ? json_decode($json, true) : $default;
    }

    public static function set(PDO $pdo, int $ownerId, string $key, mixed $value, string $type, ?string $note = null): void

    {
        $stmt = $pdo->prepare("
        INSERT INTO owner_settings 
        (owner_id, `key`, `value`, `value_json`, `type`, `note`, type_set_key, type_key)
        VALUES (:owner_id, :key, :value, :value_json, :type, :note, 'owner_setting_type', :type_key)
        ON DUPLICATE KEY UPDATE 
            value = VALUES(value),
            value_json = VALUES(value_json),
            note = VALUES(note),
            updated_at = NOW()
    ");
        $stmt->execute([
            'owner_id'   => $ownerId,
            'key'        => $key,
            'value'      => in_array($type, ['json']) ? null : (string)$value,
            'value_json' => $type === 'json' ? json_encode($value, JSON_UNESCAPED_UNICODE) : null,
            'type'       => $type,
            'type_key'   => $type,
            'note'       => $note,
        ]);
    }


    private static function getRaw(PDO $pdo, int $ownerId, string $key, string $type, ?string $default): ?string
    {
        $stmt = $pdo->prepare("SELECT value FROM owner_settings WHERE owner_id = ? AND `key` = ? AND type = ?");
        $stmt->execute([$ownerId, $key, $type]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    }
    public static function getAll(PDO $pdo, int $ownerId): array
    {
        $stmt = $pdo->prepare("SELECT `key`, `value`, `value_json`, `type` FROM owner_settings WHERE owner_id = ?");
        $stmt->execute([$ownerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];

        foreach ($rows as $row) {
            $key = $row['key'];
            $type = $row['type'];
            if ($type === 'json') {
                $result[$key] = json_decode($row['value_json'] ?? 'null', true);
            } elseif ($type === 'int') {
                $result[$key] = (int)$row['value'];
            } elseif ($type === 'float') {
                $result[$key] = (float)$row['value'];
            } elseif ($type === 'bool') {
                $result[$key] = $row['value'] === '1';
            } else {
                $result[$key] = $row['value'];
            }
        }

        return $result;
    }
}
