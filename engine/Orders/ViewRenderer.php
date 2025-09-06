<?php
// engine/orders/ViewRenderer.php — Olaj.pl V4 (bulletproof edition, enum-ready)
declare(strict_types=1);

namespace Engine\Orders;

use PDO;
use Throwable;
use function htmlspecialchars;

// ✳️ spróbuj załadować enum (gdy nie ma composera/autoloadera)
@require_once __DIR__ . '/../Enum/OrderStatus.php';
use Engine\Enum\OrderStatus;

/**
 * Pobiera wartość enuma po nazwie case'a (np. 'NOWE'), a gdy enum nie istnieje — zwraca fallback.
 */
if (!function_exists('__os_val')) {
    function __os_val(string $case, string $fallback): string
    {
        try {
            if (class_exists(\Engine\Enum\OrderStatus::class)) {
                /** @var \BackedEnum|\UnitEnum|string $obj */
                $obj = constant(\Engine\Enum\OrderStatus::class . '::' . $case);
                // dla BackedEnum mamy ->value; dla UnitEnum rzutujemy do stringa nazwę case'a
                return \is_object($obj) && property_exists($obj, 'value') ? (string)$obj->value : (string)$obj;
            }
        } catch (\Throwable $__) {
            // cicho i miękko wróć do fallbacku
        }
        return $fallback;
    }
}

if (!\function_exists('logg')) {
    /** Fallback logger – gdy includes/log.php nie zostało dołączone. */
    function logg(string $level, string $channel, string $message, array $context = [], array $extra = []): void
    {
        error_log('[logg-fallback] ' . json_encode(compact('level', 'channel', 'message', 'context', 'extra'), JSON_UNESCAPED_UNICODE));
    }
}

class ViewRenderer
{
    /* ===========================
     * Helpers — bezpieczne casty
     * =========================== */

    /** Zwraca pierwszy niepusty element z listy kandydatów. */
    private static function first(array $candidates): mixed
    {
        foreach ($candidates as $v) {
            if (isset($v) && $v !== '' && $v !== []) return $v;
        }
        return null;
    }

    /** Bezpieczny string: przyjmuje string|array|scalar|null i zwraca string. */
    private static function s(mixed $val, string $default = ''): string
    {
        if (is_string($val)) return $val;
        if (is_numeric($val)) return (string)$val;
        if (is_array($val)) {
            // najczęstsze klucze: paid_status/status/value/0
            $picked = self::first([
                $val['paid_status'] ?? null,
                $val['status'] ?? null,
                $val['value'] ?? null,
                $val[0] ?? null,
            ]);
            return self::s($picked, $default);
        }
        return $default;
    }

    /** Bezpieczny float. */
    private static function f(mixed $val, float $default = 0.0): float
    {
        if (is_float($val) || is_int($val) || (is_string($val) && is_numeric($val))) {
            return (float)$val;
        }
        if (is_array($val)) {
            $picked = self::first([
                $val['amount'] ?? null,
                $val['value'] ?? null,
                $val[0] ?? null,
            ]);
            return self::f($picked, $default);
        }
        return $default;
    }

    /** Bezpieczna tablica asocjacyjna. */
    private static function arr(mixed $val): array
    {
        return is_array($val) ? $val : [];
    }

    /** H() — HTML escape (shortcut). */
    private static function h(mixed $s): string
    {
        return htmlspecialchars((string)self::s($s), ENT_QUOTES, 'UTF-8');
    }

    /* ===========================
     * Główne metody
     * =========================== */

    /** Ładuje wszystkie dane do widoku zamówienia w panelu. */
    public static function loadOrderData(PDO $pdo, int $orderId, int $ownerId): ?array
    {
        logg('debug', 'orders', '🔍 loadOrderData() start', ['order_id' => $orderId, 'owner_id' => $ownerId]);

        try {
            // -- ORDER + CLIENT
            $stmt = $pdo->prepare("
                SELECT
                    o.*,
                    c.name  AS client_name,
                    c.token AS client_token,
                    c.email AS client_email,
                    c.phone AS client_phone,
                    c.master_client_id
                FROM orders o
                LEFT JOIN clients c
                  ON c.id = o.client_id
                 AND c.owner_id = o.owner_id
                WHERE o.id = :id
                  AND o.owner_id = :owner
                LIMIT 1
            ");
            $stmt->execute([':id' => $orderId, ':owner' => $ownerId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$order) {
                logg('warning', 'orders', '⚠️ Nie znaleziono zamówienia', ['order_id' => $orderId, 'owner_id' => $ownerId]);
                return null;
            }

            $result = ['order' => $order];

            // -- GROUPS
            $groups = [];
            try {
                $stmt = $pdo->prepare("SELECT * FROM order_groups WHERE order_id = ? ORDER BY id ASC");
                $stmt->execute([$orderId]);
                $groups = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                logg('error', 'orders', 'order_groups query failed', ['err' => $e->getMessage()]);
            }
            $result['groups'] = $groups;

            $groupIds     = \array_map('intval', \array_column($groups, 'id'));
            $firstGroupId = $groupIds[0] ?? null;
            $result['firstGroupId'] = $firstGroupId;

            $itemsByGroup = [];
            $dues = [];
            $paid = [];
            $applied = [];
            $balance = [];

            if (!empty($groupIds)) {
                $in = implode(',', array_fill(0, count($groupIds), '?'));

                // -- ITEMS
                try {
                    $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_group_id IN ($in)");
                    $stmt->execute($groupIds);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                        $gid = (int)($item['order_group_id'] ?? 0);
                        if ($gid) $itemsByGroup[$gid][] = $item;
                    }
                } catch (Throwable $e) {
                    logg('error', 'orders', 'order_items query failed', ['err' => $e->getMessage()]);
                }

                // -- DUES per group
                try {
                    $stmt = $pdo->prepare("
                        SELECT order_group_id,
                               SUM(COALESCE(total_price, unit_price * qty)) AS due
                        FROM order_items
                        WHERE order_group_id IN ($in)
                        GROUP BY order_group_id
                    ");
                    $stmt->execute($groupIds);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $gid = (int)($row['order_group_id'] ?? 0);
                        $dues[$gid] = self::f($row['due'] ?? 0);
                    }
                } catch (Throwable $e) {
                    logg('error', 'orders', 'dues query failed', ['err' => $e->getMessage()]);
                }

                // -- PAID per group
                try {
                    $stmt = $pdo->prepare("
                        SELECT order_group_id,
                               SUM(
                                 CASE
                                   WHEN status IN ('paid','refunded')
                                     THEN COALESCE(amount_captured, amount, 0) - COALESCE(amount_refunded, 0)
                                   ELSE 0
                                 END
                               ) AS paid
                        FROM payments
                        WHERE order_group_id IN ($in)
                        GROUP BY order_group_id
                    ");
                    $stmt->execute($groupIds);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $gid = (int)($row['order_group_id'] ?? 0);
                        $paid[$gid] = self::f($row['paid'] ?? 0);
                    }
                } catch (Throwable $e) {
                    logg('error', 'orders', 'paid query failed', ['err' => $e->getMessage()]);
                }

                // -- FIFO cross-cover (global pool)
                $pool = array_sum($paid);
                foreach ($groupIds as $gid) {
                    $due = (float)($dues[$gid] ?? 0.0);
                    $apply = min($pool, $due);
                    $applied[$gid] = $apply;
                    $balance[$gid] = $apply - $due; // <=0: niedopłata, >0: nadpłata
                    $pool -= $apply;
                }
            }

            $result['itemsByGroup'] = $itemsByGroup;
            $result['dues']         = $dues;
            $result['paid']         = $paid;
            $result['appliedPaid']  = $applied;
            $result['balance']      = $balance;

            // -- SHIPPING (method label + id)
            $shippingId = (int)($order['shipping_id'] ?? 0);
            $result['shippingId'] = $shippingId;

            if ($shippingId > 0) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT name
                        FROM shipping_methods
                        WHERE id = :id AND owner_id = :owner
                        LIMIT 1
                    ");
                    $stmt->execute([':id' => $shippingId, ':owner' => $ownerId]);
                    $result['shippingName'] = $stmt->fetchColumn() ?: null;
                } catch (Throwable $e) {
                    $result['shippingName'] = null;
                    logg('error', 'orders', 'shipping_methods query failed', ['err' => $e->getMessage()]);
                }
            } else {
                $result['shippingName'] = null;
            }

            // -- ADDRESS (najpierw po group_id, fallback do order_id)
            $firstAddress = null;
            if ($firstGroupId) {
                try {
                    $stmt = $pdo->prepare("SELECT * FROM shipping_addresses WHERE order_group_id = ? LIMIT 1");
                    $stmt->execute([$firstGroupId]);
                    $firstAddress = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $e) {
                    // tabela może nie mieć kolumny order_group_id – leć dalej
                }
            }
            if (!$firstAddress) {
                try {
                    $stmt = $pdo->prepare("SELECT * FROM shipping_addresses WHERE order_id = ? LIMIT 1");
                    $stmt->execute([$orderId]);
                    $firstAddress = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $e) {
                    // brak tabeli? trudno – zostaw null
                }
            }
            $result['firstAddress'] = $firstAddress;

            // -- LISTA METOD (select w panelu)
            try {
                $stmt = $pdo->prepare("SELECT id, name FROM shipping_methods WHERE owner_id = ? ORDER BY name");
                $stmt->execute([$ownerId]);
                $result['shippingMethods'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $result['shippingMethods'] = [];
            }

            // -- ORDER paid aggregate (boolean-ish)
            try {
                $stmt = $pdo->prepare("
                    SELECT MAX(
                        CASE
                          WHEN status IN ('paid','refunded')
                               AND COALESCE(amount_captured, amount, 0) > COALESCE(amount_refunded, 0)
                          THEN 1 ELSE 0
                        END
                    ) AS is_paid
                    FROM payments p
                    LEFT JOIN order_groups og ON og.id = p.order_group_id
                    WHERE COALESCE(p.order_id, og.order_id) = ?
                ");
                $stmt->execute([$orderId]);
                $result['isPaidOrder'] = ((int)$stmt->fetchColumn() === 1);
            } catch (Throwable $e) {
                $result['isPaidOrder'] = false;
            }

            logg('debug', 'orders', '✅ loadOrderData() done', [
                'order_id' => $orderId,
                'owner_id' => $ownerId,
                'groups'   => count($groups),
                'items'    => array_sum(array_map('count', $itemsByGroup ?: [[]])),
            ]);

            return $result;
        } catch (Throwable $e) {
            logg('error', 'orders', '❌ Błąd w ViewRenderer::loadOrderData()', [
                'order_id' => $orderId,
                'owner_id' => $ownerId,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /** Wiersz listy zamówień. */
    public static function renderOrderRow(array $r): string
    {
        $h     = fn(mixed $s) => self::h($s);
        $money = fn(mixed $n) => number_format(self::f($n, 0.0), 2, ',', ' ') . ' zł';

        $status = self::s($r['order_status'] ?? ''); // bezpiecznie do stringa
        $NOWE   = __os_val('NOWE', 'nowe');
        $OPEN   = __os_val('OPEN_PACKAGE', 'otwarta_paczka');
        $OPEN_A = __os_val('OPEN_PACKAGE_ADD_PRODUCTS', 'otwarta_paczka:add_products');
        $OPEN_P = __os_val('OPEN_PACKAGE_PAYMENT_ONLY', 'otwarta_paczka:payment_only');
        $READY  = __os_val('READY_TO_SHIP', 'gotowe_do_wysyłki');
        $DONE   = __os_val('COMPLETED', 'zrealizowane');

        $rowBg = match ($status) {
            $NOWE, 'oczekuje_na_dane' => 'hover:bg-stone-50',
            $OPEN, $OPEN_A, $OPEN_P    => 'hover:bg-amber-50',
            $READY                     => 'hover:bg-blue-50',
            $DONE                      => 'hover:bg-emerald-50',
            default                    => 'hover:bg-stone-50',
        };

        $clientParts = [];
        if (!empty($r['client_name']))  $clientParts[] = self::s($r['client_name']);
        $client = $clientParts ? implode(' ', $clientParts) : '—';
        if (!empty($r['client_phone'])) $client .= ' · ' . self::s($r['client_phone']);

        $total    = $r['total_amount'] ?? 0;
        $created  = !empty($r['created_at']) ? date('Y-m-d H:i', strtotime((string)$r['created_at'])) : '—';
        $lastPay  = !empty($r['last_payment_at']) ? date('Y-m-d H:i', strtotime((string)$r['last_payment_at'])) : '—';
        $wkg      = self::f($r['order_weight_kg'] ?? 0.0);
        $paidStat = self::s(self::first([
            $r['order_paid_status'] ?? null,
            $r['paid_status'] ?? null,
        ]), 'nieopłacona');

        ob_start(); ?>
        <tr class="group <?= $rowBg ?> cursor-pointer" data-order-id="<?= (int)($r['id'] ?? 0) ?>">
            <td class="px-3 py-2 align-top">
                <input type="checkbox" name="ids[]" value="<?= (int)($r['id'] ?? 0) ?>" class="rowCheck accent-blue-600">
            </td>
            <td class="px-3 py-2 align-top">
                <div class="flex items-center gap-2">
                    <span class="text-stone-900 font-medium">#<?= (int)($r['id'] ?? 0) ?></span>
                    <?= !empty($r['live_stream_id'])
                        ? '<span title="LIVE" class="text-xs">🔴</span>'
                        : '<span title="Sklep/panel" class="text-xs">⬜</span>' ?>
                </div>
            </td>
            <td class="px-3 py-2 align-top">
                <div class="text-stone-900"><?= $h($client) ?></div>
                <?php if (!empty($r['client_email'])): ?>
                    <div class="text-xs text-stone-500"><?= $h($r['client_email']) ?></div>
                <?php endif; ?>
            </td>
            <td class="px-3 py-2 align-top text-right whitespace-nowrap font-medium">
                <?= $money($total) ?>
            </td>
            <td class="px-3 py-2 align-top">
                <?= self::renderStatusBadge($status) ?>
                <?php if (!empty($r['is_packed'])): ?>
                    <span class="ml-2 px-2 py-1 text-[11px] rounded bg-emerald-50 text-emerald-700 border border-emerald-200">spakowane</span>
                <?php endif; ?>
            </td>
            <td class="px-3 py-2 align-top">
                <?= self::renderPayChip($paidStat) ?>
            </td>
            <td class="px-3 py-2 align-top">
                <span class="text-stone-700"><?= $h($r['shipping_label'] ?? '—') ?></span>
            </td>
            <td class="px-3 py-2 align-top">
                <span class="text-stone-700"><?= $h($lastPay) ?></span>
            </td>
            <td class="px-3 py-2 align-top">
                <?= self::renderWeightBadge($wkg) ?>
            </td>
            <td class="px-3 py-2 align-top">
                <span class="text-stone-700"><?= $h($created) ?></span>
            </td>
        </tr>
<?php
        return (string)ob_get_clean();
    }

    /** Chip płatności — przyjmie też tablicę albo null i ucywilizuje. */
    public static function renderPayChip(mixed $status): string
    {
        $norm = mb_strtolower(trim(self::s($status, 'nieopłacone')));

        // Prosta normalizacja znanych wariantów
        $map = [
            'nieoplacona' => 'nieopłacone',
            'nieopłacona' => 'nieopłacone',
            'nieoplacone' => 'nieopłacone',
            'oplacona'    => 'opłacone',
            'oplacone'    => 'opłacone',
            'opłacona'    => 'opłacone',
            'opłacone'    => 'opłacone',
            'czesciowa'   => 'częściowo',
            'czesciowo'   => 'częściowo',
            'nadplata'    => 'nadpłata',
        ];
        $norm = $map[$norm] ?? $norm;

        return match ($norm) {
            'opłacone' =>
                '<span class="px-2 py-1 text-xs rounded-full bg-emerald-100 text-emerald-700 border border-emerald-200">opłacone</span>',
            'częściowo' =>
                '<span class="px-2 py-1 text-xs rounded-full bg-amber-100 text-amber-700 border border-amber-200">częściowo</span>',
            'nadpłata' =>
                '<span class="px-2 py-1 text-xs rounded-full bg-indigo-100 text-indigo-700 border border-indigo-200">nadpłata</span>',
            default =>
                '<span class="px-2 py-1 text-xs rounded-full bg-stone-100 text-stone-700 border border-stone-200">nieopłacone</span>',
        };
    }

    public static function renderWeightBadge(mixed $kg): string
    {
        $val = max(0.0, self::f($kg, 0.0));
        $txt = number_format($val, 2, ',', ' ') . ' kg';
        return match (true) {
            $val < 5      => '<span class="px-2 py-1 text-xs rounded-full bg-emerald-100 text-emerald-700 border border-emerald-200" title="Lekka paczka">&lt;5 kg · ' . $txt . '</span>',
            $val <= 15    => '<span class="px-2 py-1 text-xs rounded-full bg-amber-100 text-amber-700 border border-amber-200" title="Średnia paczka">&le;15 kg · ' . $txt . '</span>',
            $val <= 25    => '<span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700 border border-red-200" title="Ciężka paczka">&le;25 kg · ' . $txt . '</span>',
            default       => '<span class="px-2 py-1 text-xs rounded-full bg-red-200 text-red-800 border border-red-300" title="Bardzo ciężka paczka">&gt;25 kg · ' . $txt . '</span>',
        };
    }

    public static function renderStatusBadge(mixed $status): string
    {
        $s = self::s($status, '—');
        $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');

        $NOWE   = __os_val('NOWE', 'nowe');
        $OPEN_A = __os_val('OPEN_PACKAGE_ADD_PRODUCTS', 'otwarta_paczka:add_products');
        $OPEN_P = __os_val('OPEN_PACKAGE_PAYMENT_ONLY', 'otwarta_paczka:payment_only');
        $WAIT   = __os_val('AWAITING_PAYMENT', 'oczekuje_na_płatność');
        $READY  = __os_val('READY_TO_SHIP', 'gotowe_do_wysyłki');
        $SHIP   = __os_val('SHIPPED', 'wysłane');
        $DONE   = __os_val('COMPLETED', 'zrealizowane');
        $CXL    = __os_val('CANCELLED', 'anulowane');
        $ARCH   = __os_val('ARCHIVED', 'zarchiwizowane');

        return match ($s) {
            $NOWE   => '<span class="inline-block px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-700 border border-blue-200">Nowe</span>',
            $OPEN_A => '<span class="inline-block px-2 py-1 text-xs rounded-full bg-amber-100 text-amber-700 border border-amber-200">Dodawanie produktów</span>',
            $OPEN_P => '<span class="inline-block px-2 py-1 text-xs rounded-full bg-amber-100 text-amber-700 border border-amber-200">Czeka na checkout</span>',
            $WAIT, 'oczekuje_na_platnosc' =>
                '<span class="inline-block px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-700 border border-yellow-200">Czeka na płatność</span>',
            $READY  =>
                '<span class="inline-block px-2 py-1 text-xs rounded-full bg-emerald-100 text-emerald-700 border border-emerald-200">Gotowe do wysyłki</span>',
            $SHIP   =>
                '<span class="inline-block px-2 py-1 text-xs rounded-full bg-indigo-100 text-indigo-700 border border-indigo-200">Wysłane</span>',
            $DONE   =>
                '<span class="inline-block px-2 py-1 text-xs rounded-full bg-stone-200 text-stone-800 border border-stone-300">Zrealizowane</span>',
            $CXL    =>
                '<span class="inline-block px-2 py-1 text-xs rounded-full bg-red-100 text-red-700 border border-red-200">Anulowane</span>',
            $ARCH   =>
                '<span class="inline-block px-2 py-1 text-xs rounded-full bg-gray-200 text-gray-700 border border-gray-300">Zarchiwizowane</span>',
            default =>
                '<span class="inline-block px-2 py-1 text-xs rounded-full bg-stone-100 text-stone-700 border border-stone-200">' . $h($s) . '</span>',
        };
    }

    public static function renderPaymentWidget(PDO $pdo, int $order_id, int $owner_id): string
    {
        ob_start();
        try {
            $partial = __DIR__ . '/partials/_payment_widget_partial.php';
            if (is_file($partial)) {
                /** @var PDO $pdo */
                /** @var int $order_id */
                /** @var int $owner_id */
                include $partial;
            } else {
                echo '<div class="text-sm text-stone-500">Brak partiala _payment_widget_partial.php</div>';
            }
        } catch (Throwable $e) {
            logg('error', 'orders', 'renderPaymentWidget include failed', ['err' => $e->getMessage()]);
            echo '<div class="text-sm text-red-600">Błąd renderowania widgetu płatności.</div>';
        }
        return (string)ob_get_clean();
    }

    public static function renderPaymentModal(PDO $pdo, int $order_id, int $owner_id, string $csrf): void
    {
        try {
            // ✅ usunięta duplikacja warunku na deleted_at
            $stmt = $pdo->prepare("
                SELECT * FROM order_groups
                WHERE order_id = ?
                  AND (deleted_at IS NULL)
                ORDER BY id ASC
            ");
            $stmt->execute([$order_id]);
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $groups = [];
            logg('error', 'orders', 'renderPaymentModal groups query failed', ['err' => $e->getMessage()]);
        }

        try {
            $partial = __DIR__ . '/partials/_payment_modal_and_script.php';
            if (is_file($partial)) {
                /** @var PDO $pdo */
                /** @var int $order_id */
                /** @var int $owner_id */
                /** @var string $csrf */
                /** @var array $groups */
                require $partial;
            } else {
                echo '<div class="p-3 text-sm text-stone-500">Brak partiala _payment_modal_and_script.php</div>';
            }
        } catch (Throwable $e) {
            logg('error', 'orders', 'renderPaymentModal include failed', ['err' => $e->getMessage()]);
            echo '<div class="p-3 text-sm text-red-600">Błąd renderowania modala płatności.</div>';
        }
    }
}
