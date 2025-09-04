<?php
// engine/Shipping/ShippingEngine.php — Olaj.pl V4
declare(strict_types=1);

namespace Engine\Shipping;

use PDO;
use Throwable;
use RuntimeException;

final class ShippingEngine
{
    /* ===========================
     * Public API
     * =========================== */

    /**
     * Lista metod dostawy dla ownera.
     * Uporządkowana po sort_order (jeśli kolumna istnieje), inaczej po id ASC.
     */
    public static function getMethods(PDO $pdo, int $ownerId, bool $onlyActive = true): array
    {
        $hasSort = self::columnExists($pdo, 'shipping_methods', 'sort_order');
        $hasActive = self::columnExists($pdo, 'shipping_methods', 'active');

        $sql = "SELECT * FROM shipping_methods WHERE owner_id = :oid";
        if ($onlyActive && $hasActive) {
            $sql .= " AND active = 1";
        }
        $sql .= $hasSort ? " ORDER BY sort_order, id" : " ORDER BY id";

        $st = $pdo->prepare($sql);
        $st->execute([':oid' => $ownerId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        self::log('info', 'shipping', 'methods.loaded', ['owner_id' => $ownerId, 'count' => count($rows)]);
        return $rows;
    }

    /**
     * Zwraca reguły wagowe dla danej metody dostawy w zunifikowanym formacie:
     * [ [min_kg, max_kg|null, price], ... ] — posortowane rosnąco po min_kg.
     *
     * Metoda dynamicznie wykrywa nazwy kolumn (min_weight_kg/min_weight, max_weight_kg/max_weight, price/cost/amount).
     */
    public static function getWeightRules(PDO $pdo, int $shippingMethodId): array
    {
        self::assertTable($pdo, 'shipping_weight_rules');

        $minCol   = self::detectFirstExistingColumn($pdo, 'shipping_weight_rules', ['min_weight_kg','min_weight','min_kg','min']);
        $maxCol   = self::detectFirstExistingColumn($pdo, 'shipping_weight_rules', ['max_weight_kg','max_weight','max_kg','max']);
        $priceCol = self::detectFirstExistingColumn($pdo, 'shipping_weight_rules', ['price','cost','amount']);

        if (!$minCol || !$priceCol) {
            self::log('warning', 'shipping', 'weight_rules.columns_missing', compact('shippingMethodId', 'minCol', 'maxCol', 'priceCol'));
            return [];
        }

        $sql = "SELECT * FROM shipping_weight_rules WHERE shipping_method_id = :mid";
        $sql .= " ORDER BY {$minCol} ASC";
        $st = $pdo->prepare($sql);
        $st->execute([':mid' => $shippingMethodId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $rules = [];
        foreach ($rows as $r) {
            $min = self::toFloat($r[$minCol] ?? 0.0);
            $max = ($maxCol && array_key_exists($maxCol, $r)) ? self::toNullableFloat($r[$maxCol]) : null;
            $pri = self::toFloat($r[$priceCol] ?? 0.0);
            $rules[] = [
                'min_kg' => $min,
                'max_kg' => $max,
                'price'  => $pri,
                '_row'   => $r, // oryginalny wiersz na potrzeby debug/log
            ];
        }

        return $rules;
    }

    /**
     * Liczy wagę paczki dla danej grupy (kg).
     * Suma: order_items.qty × products.weight_* (dynamiczna detekcja kolumny, np. weight_kg / weight_g).
     * Brak wagi → 0. Produkty custom (product_id NULL) → liczone jako 0.
     */
    public static function calculateGroupWeightKg(PDO $pdo, int $orderGroupId): float
    {
        self::assertTable($pdo, 'order_items');
        self::assertTable($pdo, 'products');

        // Wykryj kolumnę wagi i przelicznik
        $w = self::detectWeightColumn($pdo); // ['col' => 'weight_kg', 'mult' => 1.0]
        $weightCol = $w['col'];
        $mult      = $w['mult'];

        // Bezpieczny SUM z LEFT JOIN (custom items bez product_id nie podniosą nulli)
        $sql = "
            SELECT SUM(oi.qty * COALESCE(p.`{$weightCol}`, 0) * :mult) AS kg
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE oi.order_group_id = :gid
        ";

        $st = $pdo->prepare($sql);
        $st->execute([':gid' => $orderGroupId, ':mult' => $mult]);
        $kg = self::toFloat($st->fetchColumn() ?: 0);

        // Uporządkuj do rozsądnej precyzji
        $kg = max(0.0, round($kg, 3));

        self::log('info', 'shipping', 'group.weight.calculated', ['group_id' => $orderGroupId, 'kg' => $kg, 'weight_col' => $weightCol, 'mult' => $mult]);
        return $kg;
    }

    /**
     * Zwraca pełny kontekst opcji dostawy dla danej grupy:
     * - wyliczona waga
     * - lista metod z ceną dopasowaną do reguły
     * - wskazana metoda (forced / przypisana do zamówienia / najtańsza dostępna)
     *
     * Opcjonalnie, jeśli $persist = true, zapisze wybraną metodę do `orders.shipping_id` (jeśli kolumna istnieje).
     */
    public static function resolveForGroup(PDO $pdo, int $ownerId, int $orderGroupId, ?int $forcedMethodId = null, bool $persist = false): array
    {
        $weightKg   = self::calculateGroupWeightKg($pdo, $orderGroupId);
        $methods    = self::getMethods($pdo, $ownerId, true);
        $orderId    = self::getOrderIdByGroup($pdo, $orderGroupId);
        $currentSid = self::getCurrentOrderShippingId($pdo, $orderId);

        $options = [];
        foreach ($methods as $m) {
            $mid   = (int)$m['id'];
            $rules = self::getWeightRules($pdo, $mid);
            [$price, $rule] = self::matchPriceForWeight($rules, $weightKg);

            // Opcjonalny max_weight (jeśli istnieje w shipping_methods)
            $available = true;
            $reason    = null;

            $maxWeightCol = self::detectFirstExistingColumn($pdo, 'shipping_methods', ['max_weight_kg','max_weight','max_kg']);
            if ($maxWeightCol && isset($m[$maxWeightCol]) && self::toFloat($m[$maxWeightCol]) > 0) {
                $maxAllowed = self::toFloat($m[$maxWeightCol]);
                if ($weightKg > $maxAllowed) {
                    $available = false;
                    $reason    = 'exceeds_max_weight';
                }
            }

            if ($price === null) {
                // brak dopasowanej reguły wagowej
                $available = false;
                $reason    = $reason ?? 'no_weight_rule';
            }

            $options[] = [
                'method'    => $m,
                'price'     => $price,        // null jeśli niedostępna
                'rule'      => $rule,         // null jeśli brak
                'available' => $available,
                'reason'    => $available ? null : $reason,
                'selected'  => false,
            ];
        }

        // Wybór metody: forced → przypisana w orders → najtańsza dostępna
        $selectedId = null;
        if ($forcedMethodId) {
            $selectedId = $forcedMethodId;
        } elseif ($currentSid) {
            $selectedId = $currentSid;
        } else {
            $selectedId = self::pickCheapestAvailable($options);
        }

        // Zaznacz i policz cenę wybranej
        $selectedPrice = null;
        foreach ($options as &$opt) {
            $mid = (int)($opt['method']['id'] ?? 0);
            if ($mid === (int)$selectedId && $opt['available']) {
                $opt['selected'] = true;
                $selectedPrice   = $opt['price'];
                break;
            }
        }
        unset($opt);

        // Persist do orders.shipping_id (jeśli istnieje kolumna i mamy wybraną, dostępną metodę)
        if ($persist && $selectedId && self::columnExists($pdo, 'orders', 'shipping_id') && $orderId) {
            self::updateOrderShippingId($pdo, $orderId, (int)$selectedId);
        }

        $ctx = [
            'group_id'            => $orderGroupId,
            'order_id'            => $orderId,
            'weight_kg'           => $weightKg,
            'options'             => $options,
            'selected_method_id'  => $selectedId,
            'selected_price'      => $selectedPrice,
        ];

        self::log('info', 'shipping', 'resolve.group.done', [
            'group_id' => $orderGroupId,
            'order_id' => $orderId,
            'weight_kg'=> $weightKg,
            'selected_method_id' => $selectedId,
            'selected_price' => $selectedPrice,
        ]);

        return $ctx;
    }

    /**
     * Walidacja adresu dla zamówienia (minimalna, bez „wymyślania kolumn”):
     * - jeśli istnieje tabela `shipping_addresses`, sprawdza, czy jest rekord dla order_id
     * - jeżeli metoda wymaga dodatkowych pól (np. locker), ta walidacja może być rozszerzona później
     */
    public static function validateAddressForOrder(PDO $pdo, int $orderId): array
    {
        if (!self::tableExists($pdo, 'shipping_addresses')) {
            return ['ok' => true, 'reason' => null];
        }

        $col = self::detectFirstExistingColumn($pdo, 'shipping_addresses', ['order_id','orders_id','oid']);
        if (!$col) {
            // Brak kolumny łączącej — uznajmy jako OK, nic na siłę nie zakładamy
            return ['ok' => true, 'reason' => null];
        }

        $st = $pdo->prepare("SELECT 1 FROM shipping_addresses WHERE `{$col}` = :oid LIMIT 1");
        $st->execute([':oid' => $orderId]);
        $exists = (bool)$st->fetchColumn();

        return ['ok' => $exists, 'reason' => $exists ? null : 'missing_shipping_address'];
    }

    /* ===========================
     * Internal helpers
     * =========================== */

    private static function matchPriceForWeight(array $rules, float $weightKg): array
    {
        // Zasada: min_kg <= waga <= max_kg (lub brak max → ∞)
        foreach ($rules as $r) {
            $min = self::toFloat($r['min_kg'] ?? 0.0);
            $max = array_key_exists('max_kg', $r) ? self::toNullableFloat($r['max_kg']) : null;
            if ($weightKg + 1e-9 >= $min && ($max === null || $weightKg <= $max + 1e-9)) {
                return [$r['price'], $r];
            }
        }
        return [null, null];
    }

    private static function pickCheapestAvailable(array $options): ?int
    {
        $bestId    = null;
        $bestPrice = null;
        $bestOrder = null;

        foreach ($options as $opt) {
            if (!$opt['available']) {
                continue;
            }
            $mid   = (int)($opt['method']['id'] ?? 0);
            $price = self::toNullableFloat($opt['price']);
            if ($price === null) {
                continue;
            }

            // Tiebreaker: sort_order jeśli istnieje, inaczej id
            $so = $opt['method']['sort_order'] ?? null;
            $orderKey = is_numeric($so) ? (int)$so : (int)$mid;

            if ($bestPrice === null || $price < $bestPrice || ($price === $bestPrice && $orderKey < $bestOrder)) {
                $bestId    = $mid;
                $bestPrice = $price;
                $bestOrder = $orderKey;
            }
        }

        return $bestId;
    }

    private static function getOrderIdByGroup(PDO $pdo, int $orderGroupId): ?int
    {
        self::assertTable($pdo, 'order_groups');

        $col = self::detectFirstExistingColumn($pdo, 'order_groups', ['order_id','orders_id','oid']);
        if (!$col) {
            // Brak kolumny łączącej — dziwny przypadek, ale nie zakładamy nic na siłę
            return null;
        }

        $st = $pdo->prepare("SELECT `{$col}` FROM order_groups WHERE id = :gid");
        $st->execute([':gid' => $orderGroupId]);
        $val = $st->fetchColumn();
        return $val !== false ? (int)$val : null;
    }

    private static function getCurrentOrderShippingId(PDO $pdo, ?int $orderId): ?int
    {
        if (!$orderId || !self::columnExists($pdo, 'orders', 'shipping_id')) {
            return null;
        }
        $st = $pdo->prepare("SELECT shipping_id FROM orders WHERE id = :oid");
        $st->execute([':oid' => $orderId]);
        $val = $st->fetchColumn();
        return $val !== false && $val !== null ? (int)$val : null;
    }

    private static function updateOrderShippingId(PDO $pdo, int $orderId, int $shippingId): void
    {
        $st = $pdo->prepare("UPDATE orders SET shipping_id = :sid WHERE id = :oid");
        $st->execute([':sid' => $shippingId, ':oid' => $orderId]);

        self::log('info', 'shipping', 'order.shipping.updated', ['order_id' => $orderId, 'shipping_id' => $shippingId]);
    }

    /**
     * Wykrywa kolumnę wagi w `products` i zwraca też mnożnik na kg:
     * - weight_kg → mult=1.0
     * - weight_g  → mult=0.001
     * - weight    → mult=1.0 (zakładamy kg, jeśli istnieje TYLKO taka)
     */
    private static function detectWeightColumn(PDO $pdo): array
    {
        $hasKg = self::columnExists($pdo, 'products', 'weight_kg');
        if ($hasKg) return ['col' => 'weight_kg', 'mult' => 1.0];

        $hasG = self::columnExists($pdo, 'products', 'weight_g');
        if ($hasG) return ['col' => 'weight_g', 'mult' => 0.001];

        $hasGeneric = self::columnExists($pdo, 'products', 'weight');
        if ($hasGeneric) return ['col' => 'weight', 'mult' => 1.0];

        // Brak — zwróć sztuczną kolumnę 0 (COALESCE 0 i tak zadziała)
        return ['col' => 'weight_kg', 'mult' => 1.0];
    }

    /* ===========================
     * Introspection helpers
     * =========================== */

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
            return (bool)$st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function detectFirstExistingColumn(PDO $pdo, string $table, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (self::columnExists($pdo, $table, $c)) return $c;
        }
        return null;
    }

    private static function assertTable(PDO $pdo, string $table): void
    {
        if (!self::tableExists($pdo, $table)) {
            throw new RuntimeException("Missing table: {$table}");
        }
    }

    /* ===========================
     * Utils
     * =========================== */

    private static function toFloat(mixed $v): float
    {
        if ($v === null || $v === '') return 0.0;
        return (float)$v;
    }

    private static function toNullableFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        return (float)$v;
    }

    private static function log(string $level, string $channel, string $message, array $ctx = []): void
    {
        // Centralny logger V4 (jeśli istnieje)
        if (\function_exists('logg')) {
            try { \logg($level, $channel, $message, $ctx); } catch (Throwable) {}
        }
    }
    // [1] Polityka przy przekroczeniu limitu – z owner_settings (fallback = 'block')
private static function getOverweightPolicy(PDO $pdo, int $ownerId): string
{
    if (self::tableExists($pdo, 'owner_settings')) {
        $st = $pdo->prepare("SELECT value FROM owner_settings WHERE owner_id=:oid AND `key`='shipping.overweight.policy' LIMIT 1");
        $st->execute([':oid' => $ownerId]);
        $v = $st->fetchColumn();
        $v = is_string($v) ? trim($v) : '';
        if (in_array($v, ['block','warn'], true)) return $v;
    }
    return 'block';
}

/**
 * Oblicza wagę po dodaniu nowej pozycji i sprawdza, czy istnieje JAKAKOLWIEK dostępna metoda
 * z dopasowaną regułą wagową po tej zmianie. Jeśli nie – jesteśmy „overweight”.
 *
 * Zwraca: ['ok'=>bool, 'policy'=>'block|warn', 'newWeightKg'=>float, 'limitKg'=>?float, 'reason'=>?string]
 */
public static function wouldExceedMaxWeight(PDO $pdo, int $ownerId, int $groupId, float $extraWeightKg): array
{
    $policy   = self::getOverweightPolicy($pdo, $ownerId);
    $newKg    = max(0.0, round(self::calculateGroupWeightKg($pdo, $groupId) + $extraWeightKg, 3));
    $methods  = self::getMethods($pdo, $ownerId, true);

    // Czy którakolwiek metoda ma dopasowaną regułę dla newKg?
    $anyOk = false;
    $hardLimit = null;

    foreach ($methods as $m) {
        $rules = self::getWeightRules($pdo, (int)$m['id']);
        [$price, $rule] = self::matchPriceForWeight($rules, $newKg);

        // Sprawdź ewentualny max_weight w samej metodzie (jeśli istnieje)
        $maxWeightCol = self::detectFirstExistingColumn($pdo, 'shipping_methods', ['max_weight_kg','max_weight','max_kg']);
        $methodHardOk = true;
        if ($maxWeightCol && isset($m[$maxWeightCol]) && self::toFloat($m[$maxWeightCol]) > 0) {
            $hardLimit = $hardLimit === null ? self::toFloat($m[$maxWeightCol]) : min($hardLimit, self::toFloat($m[$maxWeightCol]));
            if ($newKg > self::toFloat($m[$maxWeightCol])) {
                $methodHardOk = false;
            }
        }

        if ($price !== null && $methodHardOk) {
            $anyOk = true;
            break;
        }
    }

    if ($anyOk) {
        return ['ok' => true, 'policy' => $policy, 'newWeightKg' => $newKg, 'limitKg' => $hardLimit, 'reason' => null];
    }

    return ['ok' => false, 'policy' => $policy, 'newWeightKg' => $newKg, 'limitKg' => $hardLimit, 'reason' => 'no_shipping_method_for_weight'];
}

/**
 * Powiadom klienta przez CW o przekroczeniu limitu wagi.
 * Próbuje użyć szablonu 'shipping.overweight', a jeśli brak – wysyła fallback tekstowy.
 */
public static function notifyOverweightViaCw(PDO $pdo, int $ownerId, int $clientId, int $orderGroupId, float $weightKg, ?float $limitKg = null): void
{
    $payload = [
        'event'        => 'shipping.overweight',
        'owner_id'     => $ownerId,
        'client_id'    => $clientId,
        'order_group'  => $orderGroupId,
        'weight_kg'    => $weightKg,
        'limit_kg'     => $limitKg,
    ];

    // Preferuj Cw::enqueue (jeśli masz klasę Cw), inaczej CwHelper fallback
    try {
        if (class_exists('\\Engine\\CentralMessaging\\Cw')) {
            \Engine\CentralMessaging\Cw::enqueue($pdo, $ownerId, $clientId, 'dm', 'shipping.overweight', $payload);
        } elseif (class_exists('\\Engine\\CentralMessaging\\CwHelper')) {
            // Fallback – wyślij tekstową
            $txt = self::buildOverweightMessage($weightKg, $limitKg);
            \Engine\CentralMessaging\CwHelper::sendAutoReply($pdo, $ownerId, $clientId, $txt, ['group_id' => $orderGroupId]);
        } else {
            self::log('warning', 'shipping', 'cw.not_available', $payload);
        }
    } catch (\Throwable $e) {
        self::log('error', 'shipping', 'cw.enqueue.failed', $payload + ['err' => $e->getMessage()]);
    }
}

private static function buildOverweightMessage(float $weightKg, ?float $limitKg): string
{
    $lim = $limitKg !== null ? number_format($limitKg, 2, ',', ' ') . ' kg' : 'wyznaczony';
    $w   = number_format($weightKg, 2, ',', ' ');
    return "Hejj! 🙏 Twoja paczka waży teraz {$w} kg i przekracza {$lim} limit. Nie mogę dodać więcej produktów do tej paczki. Daj znać, jeśli chcesz utworzyć drugą paczkę albo coś odjąć. ❤️";
}


/** Zwraca ID reguły (wiersza z shipping_weight_rules), która pasuje do wagi. */
private static function matchRuleIdForWeight(PDO $pdo, array $rules, float $weightKg): ?int
{
    foreach ($rules as $r) {
        $min = self::toFloat($r['min_kg'] ?? 0.0);
        $max = array_key_exists('max_kg', $r) ? self::toNullableFloat($r['max_kg']) : null;
        $rid = isset($r['_row']['id']) ? (int)$r['_row']['id'] : null;
        if ($rid && $weightKg + 1e-9 >= $min && ($max === null || $weightKg <= $max + 1e-9)) {
            return $rid;
        }
    }
    return null;
}

/**
 * Pobiera pełny wiersz reguły z shipping_weight_rules po id (do payloadu CW).
 */
private static function getRuleRow(PDO $pdo, int $ruleId): ?array
{
    if (!self::tableExists($pdo, 'shipping_weight_rules')) return null;
    $st = $pdo->prepare("SELECT * FROM shipping_weight_rules WHERE id = :id");
    $st->execute([':id' => $ruleId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Czy sygnał (enter/leave) był już wysłany? Dba o idempotencję.
 */
private static function wasSignalSent(PDO $pdo, int $ownerId, int $groupId, int $ruleId, string $direction): bool
{
    if (!self::tableExists($pdo, 'shipping_weight_signals')) return false;
    $st = $pdo->prepare("SELECT 1 FROM shipping_weight_signals
                         WHERE owner_id=:oid AND order_group_id=:gid AND rule_id=:rid AND direction=:dir LIMIT 1");
    $st->execute([':oid'=>$ownerId, ':gid'=>$groupId, ':rid'=>$ruleId, ':dir'=>$direction]);
    return (bool)$st->fetchColumn();
}

/**
 * Zapis idempotencji sygnału.
 */
private static function markSignalSent(PDO $pdo, int $ownerId, int $groupId, int $ruleId, string $direction): void
{
    if (!self::tableExists($pdo, 'shipping_weight_signals')) return;
    $st = $pdo->prepare("INSERT IGNORE INTO shipping_weight_signals (owner_id, order_group_id, rule_id, direction)
                         VALUES (:oid, :gid, :rid, :dir)");
    $st->execute([':oid'=>$ownerId, ':gid'=>$groupId, ':rid'=>$ruleId, ':dir'=>$direction]);
}

/**
 * Zwraca [rules_by_method, ruleId_for_old, ruleId_for_new, entered[], left[]]
 * gdzie entered/left to listy [method_id => [rule_id,...]] – bo group może mieć wiele metod dostępnych.
 *
 * Uwaga: Semantyka „enter/leave” dotyczy **reguł wagowych**, nie wybranej metody.
 */
public static function diffWeightBrackets(PDO $pdo, int $ownerId, float $oldKg, float $newKg): array
{
    $methods = self::getMethods($pdo, $ownerId, true);
    $entered = [];
    $left    = [];
    $rulesByMethod = [];

    foreach ($methods as $m) {
        $mid   = (int)$m['id'];
        $rules = self::getWeightRules($pdo, $mid);
        $rulesByMethod[$mid] = $rules;

        $oldRid = self::matchRuleIdForWeight($pdo, $rules, $oldKg);
        $newRid = self::matchRuleIdForWeight($pdo, $rules, $newKg);

        if ($oldRid !== $newRid) {
            if ($newRid !== null) $entered[$mid][] = $newRid;
            if ($oldRid !== null) $left[$mid][]    = $oldRid;
        }
    }

    return [
        'rules_by_method' => $rulesByMethod,
        'entered'         => $entered,
        'left'            => $left,
    ];
}

/**
 * Wyślij CW dla wejścia/wyjścia z reguł – respektuje notify_on_enter/leave i idempotencję.
 * Można wołać po każdej zmianie zawartości paczki.
 */
public static function emitWeightBracketSignals(PDO $pdo, int $ownerId, int $orderGroupId, int $clientId, float $oldKg, float $newKg): void
{
    $diff = self::diffWeightBrackets($pdo, $ownerId, $oldKg, $newKg);

    // ENTER
    foreach ($diff['entered'] as $mid => $ruleIds) {
        foreach ($ruleIds as $rid) {
            $rule = self::getRuleRow($pdo, (int)$rid);
            if (!$rule) continue;

            $notifyEnter = array_key_exists('notify_on_enter', $rule) ? (int)$rule['notify_on_enter'] === 1 : false;
            if (!$notifyEnter) continue;

            if (self::wasSignalSent($pdo, $ownerId, $orderGroupId, (int)$rid, 'enter')) continue;

            self::sendBracketMessage($pdo, $ownerId, $clientId, $orderGroupId, $rule, 'enter', $newKg);
            self::markSignalSent($pdo, $ownerId, $orderGroupId, (int)$rid, 'enter');
        }
    }

    // LEAVE
    foreach ($diff['left'] as $mid => $ruleIds) {
        foreach ($ruleIds as $rid) {
            $rule = self::getRuleRow($pdo, (int)$rid);
            if (!$rule) continue;

            $notifyLeave = array_key_exists('notify_on_leave', $rule) ? (int)$rule['notify_on_leave'] === 1 : false;
            if (!$notifyLeave) continue;

            if (self::wasSignalSent($pdo, $ownerId, $orderGroupId, (int)$rid, 'leave')) continue;

            self::sendBracketMessage($pdo, $ownerId, $clientId, $orderGroupId, $rule, 'leave', $newKg);
            self::markSignalSent($pdo, $ownerId, $orderGroupId, (int)$rid, 'leave');
        }
    }
}

/**
 * Wysyłka wiadomości CW na podstawie reguły wagowej.
 * Używa rule.cw_template_key jeśli podane, inaczej fallback: shipping.weight.bracket.enter/leave
 */
private static function sendBracketMessage(PDO $pdo, int $ownerId, int $clientId, int $groupId, array $ruleRow, string $direction, float $kg): void
{
    $tpl = null;
    if (isset($ruleRow['cw_template_key']) && is_string($ruleRow['cw_template_key']) && trim($ruleRow['cw_template_key']) !== '') {
        $tpl = trim($ruleRow['cw_template_key']);
    } else {
        $tpl = $direction === 'enter' ? 'shipping.weight.bracket.enter' : 'shipping.weight.bracket.leave';
    }

    $minCol   = self::detectFirstExistingColumn($pdo, 'shipping_weight_rules', ['min_weight_kg','min_weight','min_kg','min']) ?? 'min';
    $maxCol   = self::detectFirstExistingColumn($pdo, 'shipping_weight_rules', ['max_weight_kg','max_weight','max_kg','max']);
    $priceCol = self::detectFirstExistingColumn($pdo, 'shipping_weight_rules', ['price','cost','amount']) ?? 'price';

    $min = isset($ruleRow[$minCol])   ? self::toFloat($ruleRow[$minCol]) : 0.0;
    $max = ($maxCol && isset($ruleRow[$maxCol])) ? self::toNullableFloat($ruleRow[$maxCol]) : null;
    $pri = isset($ruleRow[$priceCol]) ? self::toFloat($ruleRow[$priceCol]) : 0.0;

    $payload = [
        'event'        => $tpl,
        'owner_id'     => $ownerId,
        'client_id'    => $clientId,
        'order_group'  => $groupId,
        'direction'    => $direction,
        'weight_kg'    => round($kg, 3),
        'min_kg'       => $min,
        'max_kg'       => $max,
        'price'        => $pri,
        'rule_id'      => (int)($ruleRow['id'] ?? 0),
    ];

    try {
        if (class_exists('\\Engine\\CentralMessaging\\Cw')) {
            \Engine\CentralMessaging\Cw::enqueue($pdo, $ownerId, $clientId, 'dm', $tpl, $payload);
        } elseif (class_exists('\\Engine\\CentralMessaging\\CwHelper')) {
            $text = self::buildBracketText($direction, $kg, $min, $max, $pri);
            \Engine\CentralMessaging\CwHelper::sendAutoReply($pdo, $ownerId, $clientId, $text, ['group_id' => $groupId]);
        } else {
            self::log('warning', 'shipping', 'cw.not_available', $payload);
        }
    } catch (Throwable $e) {
        self::log('error', 'shipping', 'cw.enqueue.failed', $payload + ['err' => $e->getMessage()]);
    }
}

private static function buildBracketText(string $direction, float $kg, float $min, ?float $max, float $price): string
{
    $w = number_format($kg, 2, ',', ' ');
    $mi = number_format($min, 2, ',', ' ');
    $mx = $max !== null ? number_format($max, 2, ',', ' ') : '∞';
    $pr = number_format($price, 2, ',', ' ');
    if ($direction === 'enter') {
        return "Hejj! 📦 Twoja paczka osiągnęła nowy przedział wagi: {$mi}–{$mx} kg (aktualnie {$w} kg). Szacowana cena dostawy dla tego przedziału: {$pr} zł.";
    }
    return "Info 📦 Paczka opuściła przedział {$mi}–{$mx} kg (aktualnie {$w} kg). Cena dostawy może się zmienić.";
}
}
