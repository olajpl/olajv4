<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

// logger (centralny). Jeśli brak — zróbmy miękki fallback
if (is_file(__DIR__ . '/log.php')) {
    require_once __DIR__ . '/log.php';
}
if (!function_exists('wlog')) {
    function wlog(string $msg, array $ctx = []): void { /* no-op fallback */ }
}
if (!function_exists('logg')) {
    function logg(string $level, string $channel, string $message, array $context = [], array $extra = []): void { /* no-op */ }
}

/**
 * Pobiera wartość float z settings.
 */
if (!function_exists('getSettingFloat')) {
    function getSettingFloat(PDO $pdo, int $ownerId, string $key, ?float $default = null): ?float {
        $st = $pdo->prepare("SELECT value FROM settings WHERE owner_id=:oid AND `key`=:k LIMIT 1");
        $st->execute(['oid'=>$ownerId, 'k'=>$key]);
        $v = $st->fetchColumn();
        return ($v !== false) ? (float)$v : $default;
    }
}

if (!function_exists('getWeightUnit')) {
    function getWeightUnit(PDO $pdo, int $ownerId): string {
        $st = $pdo->prepare("SELECT value FROM settings WHERE owner_id=:oid AND `key`='weight_unit' LIMIT 1");
        $st->execute(['oid'=>$ownerId]);
        $v = strtolower((string)($st->fetchColumn() ?: 'kg'));
        return in_array($v, ['kg','g'], true) ? $v : 'kg';
    }
}
/**
 * Łączna waga całego zamówienia (sumujemy wszystkie PGZ)
 */
if (!function_exists('getOrderTotalWeight')) {
    function getOrderTotalWeight(PDO $pdo, int $orderId, ?string $unit = null, ?int $ownerId = null): float {
        if ($unit === null && $ownerId !== null) {
            $unit = getWeightUnit($pdo, $ownerId);
        }
        if ($unit === null) $unit = 'kg';

        // SQL-owy wyraz wagi produktu w KG
        $expr = ($unit === 'g')
          ? "CAST(REPLACE(p.weight, ',', '.') AS DECIMAL(12,4))/1000.0"
          : "CAST(REPLACE(p.weight, ',', '.') AS DECIMAL(12,4))";

        $sql = "
            SELECT COALESCE(SUM(COALESCE($expr,0) * COALESCE(oi.quantity,0)),0) AS w
            FROM order_groups og
            JOIN order_items oi ON oi.order_group_id = og.id
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE og.order_id = :oid
        ";
        $q = $pdo->prepare($sql);
        $q->execute(['oid'=>$orderId]);
        return (float)($q->fetchColumn() ?: 0.0);
    }
}

/**
 * Limit operacyjny (payload na 1 paczkę). Domyślnie 23.0 kg, chyba że nadpiszesz w settings.parcel_operational_limit_kg.
 */
if (!function_exists('getOperationalParcelLimitKg')) {
    function getOperationalParcelLimitKg(PDO $pdo, int $ownerId): float {
        $op = getSettingFloat($pdo, $ownerId, 'parcel_operational_limit_kg', null);
        return ($op !== null && $op > 0) ? $op : 23.0;
    }
}

/**
 * Cena wg reguły wagowej dla „pojedynczej paczki” o wadze $w.
 * Jeśli brak reguły — użyj default_price z shipping_methods.
 */
if (!function_exists('resolveWeightRulePrice')) {
    function resolveWeightRulePrice(PDO $pdo, int $shippingMethodId, float $w, ?float $fallbackPrice): float {
        $st = $pdo->prepare("
          SELECT price
          FROM shipping_weight_rules
          WHERE shipping_method_id = :mid
            AND (min_weight IS NULL OR min_weight <= :w1)
            AND (max_weight IS NULL OR max_weight >= :w2)
          ORDER BY
            (CASE WHEN min_weight IS NULL THEN 0 ELSE 1 END) DESC,
            COALESCE(min_weight, 0) DESC
          LIMIT 1
        ");
        $st->execute(['mid'=>$shippingMethodId, 'w1'=>$w, 'w2'=>$w]);
        $p = $st->fetchColumn();
        return ($p !== false) ? (float)$p : (float)($fallbackPrice ?? 0.0);
    }
}

/**
 * Główna kalkulacja: koszt wysyłki całego ZAMÓWIENIA (skonsolidowany, 23kg operacyjnie).
 * Zwraca:
 * [
 *   'total_cost' => float,
 *   'total_weight' => float,
 *   'parcels' => int,
 *   'per_parcel_weights' => float[],
 *   'limit_kg' => float
 * ]
 */
// Konsolidacja kosztu dostawy z WAGI całego zamówienia (bez patrzenia na liczbę PGZ)
if (!function_exists('calcConsolidatedOrderShipping')) {
    // Konsolidacja kosztu: tymczasowo BEZ dopłat za wagę / dodatkowe paczki.
// Zwracamy koszt jak za 1 paczkę (default_price), ale nadal liczymy total_kg do wyświetlenia.
if (!function_exists('calcConsolidatedOrderShipping')) {
    function calcConsolidatedOrderShipping(PDO $pdo, int $ownerId, int $orderId, int $shippingMethodId): array {
        // 1) Waga zamówienia (KG) – przydaje się do informacji
        $totalKg = getOrderTotalWeight($pdo, $orderId, null, $ownerId);

        // 2) Cena za paczkę z metody (bez "max_weight_kg" – nie wymagamy tej kolumny)
        $st = $pdo->prepare("
            SELECT COALESCE(default_price,0) AS price_per_parcel
            FROM shipping_methods
            WHERE id = :sid AND owner_id = :oid
            LIMIT 1
        ");
        $st->execute([':sid'=>$shippingMethodId, ':oid'=>$ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['price_per_parcel'=>0];
        $pricePerParcel = (float)($row['price_per_parcel'] ?? 0.0);

        // 3) Limit operacyjny tylko informacyjnie (np. 23 kg)
        $opLimit = getOperationalParcelLimitKg($pdo, $ownerId); // fallback 23.0

        // 4) Tymczasowo zawsze 1 paczka, gdy waga > 0
        $parcels = ($totalKg > 0) ? 1 : 0;
// ... po złożeniu $result / $payload:
$payload = [
  'total_cost'       => $parcels ? $pricePerParcel : 0.0,
  'parcel_count'     => $parcels,
  'limit_kg'         => $opLimit,
  'total_kg'         => $totalKg,
  'price_per_parcel' => $pricePerParcel,
  'rules_suspended'  => true,
];

/* === ALIASY dla starego kodu (żeby nic więcej nie ruszać) === */
$payload['parcels']      = $payload['parcel_count']; // stary klucz
$payload['weight_total'] = $payload['total_kg'];
$payload['limit']        = $payload['limit_kg'];
$payload['cost']         = $payload['total_cost'];

return $payload;

        return [
            'total_cost'       => $parcels ? $pricePerParcel : 0.0,
            'parcel_count'     => $parcels,              // zawsze 0 albo 1 w tym trybie
            'limit_kg'         => $opLimit,
            'total_kg'         => $totalKg,
            'price_per_parcel' => $pricePerParcel,
            'rules_suspended'  => true,                  // flaga informacyjna (możesz użyć w UI)
        ];
    }
}
}// Alias dla zgodności wstecznej
if (!function_exists('calcOrderShippingConsolidated')) {
    function calcOrderShippingConsolidated(PDO $pdo, int $ownerId, int $orderId, int $shippingMethodId): array {
        return calcConsolidatedOrderShipping($pdo, $ownerId, $orderId, $shippingMethodId);
    }
}
