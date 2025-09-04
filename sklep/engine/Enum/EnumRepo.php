<?php
// engine/Enum/EnumRepo.php
declare(strict_types=1);

namespace Engine\Enum;

use PDO;
use PDOException;

final class EnumRepo
{
    /**
     * cache[cacheKey=setKey|ownerId] = ['until'=>int,'map'=>array<string,string>]
     * map: value_key => label
     */
    private static array $cache = [];
    private const TTL = 60; // sekundy

    /** Zwraca label dla danej wartości albo null, jeśli brak. */
    public static function label(PDO $pdo, string $setKey, string $valueKey, ?int $ownerId = null): ?string
    {
        $map = self::values($pdo, $setKey, $ownerId);
        return $map[$valueKey] ?? null;
    }

    /** Alias do values() bez ownera – dla wstecznej kompatybilności. */
    public static function all(PDO $pdo, string $setKey): array
    {
        return self::values($pdo, $setKey, null);
    }

    /**
     * Główna metoda repo: pobiera aktywne wartości z enum_values,
     * biorąc pod uwagę owner_id (global + per_owner).
     *
     * @return array<string,string> value_key => label
     */
    public static function values(PDO $pdo, string $setKey, ?int $ownerId = null): array
    {
        $oid  = $ownerId ?? 0; // 0 = global
        $ckey = $setKey . '|' . $oid;
        $now  = time();

        if (isset(self::$cache[$ckey]) && $now < (self::$cache[$ckey]['until'] ?? 0)) {
            return self::$cache[$ckey]['map'];
        }

        try {
            // global + per_owner override (per_owner ma pierwszeństwo w SELECT – sortujemy aktywny per_owner nad global)
            $sql = "
                SELECT ev.value_key,
                       COALESCE(ev.label, ev.value_key) AS label
                FROM enum_values ev
                WHERE ev.set_key = :set
                  AND ev.active = 1
                  AND (ev.owner_id IS NULL OR ev.owner_id = :oid)
                ORDER BY
                    -- per_owner najpierw (żeby nadpisać global, jeśli viewer scala)
                    (ev.owner_id IS NOT NULL) DESC,
                    ev.sort_order ASC,
                    ev.value_key ASC
            ";
            $st = $pdo->prepare($sql);
            $st->execute([':set' => $setKey, ':oid' => $ownerId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // zbij w mapę, ale nie nadpisuj wartości jeśli już była (pierwszeństwo miały per_owner)
            $map = [];
            foreach ($rows as $r) {
                $vk = (string)$r['value_key'];
                if (!array_key_exists($vk, $map)) {
                    $map[$vk] = (string)$r['label'];
                }
            }

            self::$cache[$ckey] = ['until' => $now + self::TTL, 'map' => $map];
            return $map;
        } catch (PDOException $e) {
            // w razie problemów z bazą – zwróć pustą mapę, ale nie wywalaj aplikacji
            self::$cache[$ckey] = ['until' => $now + 5, 'map' => []];
            return [];
        }
    }

    /** Rzuca wyjątek, jeśli valueKey nie jest dozwolony w danym zbiorze. */
    public static function ensureAllowed(PDO $pdo, string $setKey, string $valueKey, ?int $ownerId = null): void
    {
        if ($valueKey === '' || !array_key_exists($valueKey, self::values($pdo, $setKey, $ownerId))) {
            throw new \InvalidArgumentException("Invalid enum value '$valueKey' for set '$setKey'");
        }
    }

    /**
     * Bezpiecznie wybiera wartość:
     * - jeśli $proposed istnieje → zwraca ją
     * - jeśli $fallback istnieje → zwraca fallback
     * - inaczej pierwszy dostępny z zestawu
     * - jeśli zestaw pusty → zwraca $proposed (niech wyżej zdecyduje)
     */
    public static function pick(PDO $pdo, string $setKey, string $proposed, ?int $ownerId = null, ?string $fallback = null): string
    {
        $vals = self::values($pdo, $setKey, $ownerId);
        if (isset($vals[$proposed])) {
            return $proposed;
        }
        if ($fallback && isset($vals[$fallback])) {
            return $fallback;
        }
        $first = array_key_first($vals);
        return $first ?? $proposed;
    }
}
