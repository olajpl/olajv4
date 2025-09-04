<?php
// engine/Orders/OrderEngine.php — Olaj.pl V4 (ENUM Engine edition)
declare(strict_types=1);

namespace Engine\Orders;

use PDO;
use Throwable;
use RuntimeException;

if (!\function_exists('logg')) {
    function logg(string $level, string $channel, string $message, array $context = [], array $extra = []): void
    {
        error_log('[logg-fallback] ' . json_encode(compact('level', 'channel', 'message', 'context', 'extra'), JSON_UNESCAPED_UNICODE));
    }
}

/**
 * OrderEngine — kompatybilny z OLAJ_V4_REFAKTOR_ENUM_ENGINE
 *
 * Kluczowe założenia:
 * - orders:  używamy order_status_set_key='order_status', order_status_key IN (...)
 * - groups:  paid_status_set_key='group_paid_status', paid_status_key='unpaid'|'paid'|...
 * - items:   source_type_set_key='order_item_source', source_type_key='parser'|... (+ opcjonalnie legacy source_type)
 * - tokens:  orders.checkout_token (chk-...), order_groups.group_token (grp-...)
 */
final class OrderEngine
{
    public function __construct(private PDO $pdo) {}

    /* ============================================================
     * PUBLIC API
     * ============================================================ */

    /**
     * Dodaje pozycję do zamówienia; engine zapewnia utworzenie orders + order_groups.
     *
     * @param array{
     *   owner_id:int, client_id:int, product_id?:int|null,
     *   name:string, qty:float|int, unit_price:float|int, vat_rate:float|int, sku?:string,
     *   source_type?:string, channel?:string
     * } $payload
     * @return array{ok:bool, order_id?:int, order_group_id?:int, order_item_id?:int, checkout_token?:string, reason?:string, message?:string, sql_state?:string, sql_code?:int|string, sql_msg?:string}
     */
    public function addOrderItem(array $payload): array
{
    $ownerId    = (int)($payload['owner_id']   ?? 0);
    $clientId   = (int)($payload['client_id']  ?? 0);
    $productId  = isset($payload['product_id']) ? (int)$payload['product_id'] : null;
    $name       = trim((string)($payload['name'] ?? ''));
    $qty        = (float)($payload['qty'] ?? 1.0);
    $unitPrice  = (float)($payload['unit_price'] ?? 0.0);
    $vatRate    = (float)($payload['vat_rate'] ?? 23.0);
    $sku        = (string)($payload['sku'] ?? '');
    $sourceType = (string)($payload['source_type'] ?? 'parser'); // enum: 'order_item_source'
    $channel    = (string)($payload['channel'] ?? '');

    if ($channel === '') {
        $channel = match ($sourceType) {
            'parser' => 'messenger',
            'shop'   => 'shop',
            'live'   => 'live',
            'manual' => 'admin',
            default  => 'unknown',
        };
    }

    if ($ownerId <= 0 || $clientId <= 0 || $name === '' || $qty <= 0.0) {
        $this->safeLog('error', 'orderengine', 'addItem:invalid_payload', [
            'owner_id' => $ownerId,
            'client_id' => $clientId,
            'payload' => $payload
        ]);
        return ['ok' => false, 'reason' => 'invalid_payload'];
    }

    try {
        $this->pdo->beginTransaction();

        // 1) Order + grupa (enum-aware)
       $og = $this->ensureOrderAndOpenGroup($ownerId, $clientId, $channel);

        $orderId       = (int)$og['order_id'];
        $groupId       = (int)$og['order_group_id'];
        $checkoutToken = (string)$og['checkout_token'];

        // sanity: grupa należy do ordera
        $chk = $this->pdo->prepare("SELECT 1 FROM order_groups WHERE id = :gid AND order_id = :oid LIMIT 1");
        $chk->execute([':gid' => $groupId, ':oid' => $orderId]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException("order_group mismatch or missing (order_id={$orderId}, group_id={$groupId})");
        }

        // 2) INSERT do order_items (enum-aware dla source_type)
        $hasLegacySourceType = $this->columnExists('order_items', 'source_type');
        $hasSourceChannel    = $this->columnExists('order_items', 'source_channel');
        $hasChannel          = $this->columnExists('order_items', 'channel');

        $cols = [
            'owner_id',
            'order_id',
            'order_group_id',
            'product_id',
            'name',
            'qty',
            'unit_price',
            'vat_rate',
            'sku',
            'source_type_set_key',
            'source_type_key'
        ];
        $vals = [
            ':owner_id',
            ':order_id',
            ':order_group_id',
            ':product_id',
            ':name',
            ':qty',
            ':unit_price',
            ':vat_rate',
            ':sku',
            ':st_set',
            ':st_key'
        ];
        $params = [
            ':owner_id'       => $ownerId,
            ':order_id'       => $orderId,
            ':order_group_id' => $groupId,
            ':product_id'     => $productId,
            ':name'           => $name,
            ':qty'            => $qty,
            ':unit_price'     => $unitPrice,
            ':vat_rate'       => $vatRate,
            ':sku'            => $sku,
            ':st_set'         => 'order_item_source',
            ':st_key'         => $sourceType,
        ];

        if ($hasLegacySourceType) {
            $cols[] = 'source_type';
            $vals[] = ':source_type';
            $params[':source_type'] = $sourceType;
        }

        if ($hasSourceChannel && $channel !== '') {
            $cols[] = 'source_channel';
            $vals[] = ':source_channel';
            $params[':source_channel'] = $channel;
        }

        if ($hasChannel && $channel !== '') {
            $cols[] = 'channel';
            $vals[] = ':channel';
            $params[':channel'] = $channel;
        }

        $sql = "INSERT INTO order_items (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
        $st  = $this->pdo->prepare($sql);
        $st->execute($params);
        $orderItemId = (int)$this->pdo->lastInsertId();

        $this->pdo->commit();

        if ($channel !== '') {
            if (!\is_string($channel)) {
    $this->safeLog('warning', 'orderengine', 'invalid_channel_type', ['channel' => $channel, 'type' => \gettype($channel)]);
    $channel = (string)$channel;
}

          $this->updateOrderSourceChannel($orderId, $channel);

        }

        $this->safeLog('info', 'orderengine', 'addItem:ok', [
            'owner_id'       => $ownerId,
            'order_id'       => $orderId,
            'order_group_id' => $groupId,
            'order_item_id'  => $orderItemId,
            'checkout_token' => $checkoutToken,
        ]);

        return [
            'ok'             => true,
            'order_id'       => $orderId,
            'order_group_id' => $groupId,
            'order_item_id'  => $orderItemId,
            'checkout_token' => $checkoutToken,
        ];
    } catch (\PDOException $e) {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        $info = $e->errorInfo;
        $this->safeLog('error', 'orderengine', 'addItem:pdo_ex', [
            'owner_id'    => $ownerId,
            'sqlstate'    => $info[0] ?? null,
            'driver_code' => $info[1] ?? null,
            'message'     => $info[2] ?? $e->getMessage(),
        ]);
        return [
            'ok'       => false,
            'reason'   => 'sql_exception',
            'sql_state' => (string)($info[0] ?? ''),
            'sql_code' => (string)($info[1] ?? ''),
            'sql_msg'  => (string)($info[2] ?? $e->getMessage()),
        ];
    } catch (Throwable $e) {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        $this->safeLog('error', 'orderengine', 'addItem:ex', [
            'owner_id' => $ownerId,
            'client_id' => $clientId,
            'err' => $e->getMessage()
        ]);
        return ['ok' => false, 'reason' => 'exception', 'message' => $e->getMessage()];
    }
}

    /**
 * Ustawia source_channel w zamówieniu, jeśli nie było ustawione lub jest 'shop' (domyślne).
 */
private function updateOrderSourceChannel(int $orderId, string $channel): void
{
    try {
        $stmt = $this->pdo->prepare("UPDATE orders SET source_channel = :ch WHERE id = :id AND (source_channel IS NULL OR source_channel = 'shop')");
        $stmt->execute([':ch' => $channel, ':id' => $orderId]);

        if ($stmt->rowCount() > 0) {
            $this->safeLog('debug', 'orderengine', 'source_channel_updated', [
                'order_id' => $orderId,
                'channel'  => $channel
            ]);
        }
    } catch (Throwable $e) {
        $this->safeLog('warning', 'orderengine', 'source_channel_update_failed', [
            'order_id' => $orderId,
            'channel'  => $channel,
            'error'    => $e->getMessage(),
        ]);
    }
}


    /**
     * Ustawia dodatkowe pola zamówienia (np. po wyborze adresu, etykiety, źródła)
     *
     * @param int $orderId
     * @param array<string, mixed> $fields
     * @return bool
     */
    public function updateOrderMeta(int $orderId, array $fields): bool
    {
        if ($orderId <= 0 || empty($fields)) {
            $this->safeLog('warning', 'orderengine', 'updateMeta:invalid', [
                  'owner_id' => $ownerId,
    'client_id' => $clientId,
                'order_id' => $orderId, 'fields' => $fields]);
            return false;
        }

        $allowed = [
            'shipping_address_id',
            'shipping_label_id',
            'package_weight',
            'invoice_requested',
            'source_channel',
            'source_context',
            'created_by_user_id',
            'is_locked',
        ];

        $set = [];
        $params = [':id' => $orderId];
        foreach ($fields as $k => $v) {
            if (!\in_array($k, $allowed, true)) continue;
            $param = ':' . $k;
            $set[] = "`{$k}` = {$param}";
            $params[$param] = $v;
        }

        if (empty($set)) {
            $this->safeLog('warning', 'orderengine', 'updateMeta:no_allowed_fields', [
                  'owner_id' => $ownerId,
    'client_id' => $clientId,

                'order_id' => $orderId]);
            return false;
        }

        $sql = "UPDATE orders SET " . implode(', ', $set) . " WHERE id = :id LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /* ============================================================
     * CORE HELPERS (ENUM-aware)
     * ============================================================ */

    /**
     * Zapewnia istnienie zamówienia (orders) i otwartej grupy (order_groups).
     * - orders:  order_status_set_key='order_status', order_status_key IN OPEN_SET
     * - groups:  checkout_completed=0 oraz paid_status_set_key='group_paid_status', paid_status_key='unpaid'
     *
     * @return array{order_id:int, order_group_id:int, checkout_token:string}
     */
    private function ensureOrderAndOpenGroup(int $ownerId, int $clientId, string $channel = ''): array
{
    $openKeys = $this->getOpenOrderStatusKeys();

    // 1) znajdź istniejący order (ENUM engine)
    $ph = [];
    $params = [':oid' => $ownerId, ':cid' => $clientId, ':st' => 'order_status'];
    foreach ($openKeys as $i => $k) {
        $kk = ':k' . $i;
        $ph[] = $kk;
        $params[$kk] = $k;
    }
    $in = implode(',', $ph);

    $sql = "
        SELECT id, checkout_token
          FROM orders
         WHERE owner_id = :oid
           AND client_id = :cid
           AND order_status_set_key = :st
           AND order_status_key IN ($in)
         ORDER BY id DESC
         LIMIT 1
    ";
    $st = $this->pdo->prepare($sql);
    $st->execute($params);
    $order = $st->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        // utwórz nowy order: status 'new'
        $chk = self::generateCheckoutToken();
        $ins = $this->pdo->prepare("
            INSERT INTO orders (
                owner_id, client_id,
                order_status_set_key, order_status_key,
                checkout_token, checkout_completed,
                source_channel,
                created_at
            ) VALUES (
                :oid, :cid,
                'order_status', :key,
                :ct, 0,
                :ch,
                NOW()
            )
        ");
        $ins->execute([
            ':oid' => $ownerId,
            ':cid' => $clientId,
            ':key' => $this->getOrderStatusKeyNew(),
            ':ct'  => $chk,
            ':ch'  => $channel,
        ]);
        $order = [
            'id' => (int)$this->pdo->lastInsertId(),
            'checkout_token' => $chk,
        ];
    }

    $orderId = (int)$order['id'];
    $orderCheckoutToken = (string)$order['checkout_token'];

    // 2) znajdź/utwórz otwartą grupę (group_token + paid_status)
    $st = $this->pdo->prepare("
        SELECT id, group_token
          FROM order_groups
         WHERE order_id = :oid
           AND (checkout_completed IS NULL OR checkout_completed = 0)
         ORDER BY id DESC
         LIMIT 1
    ");
    $st->execute([':oid' => $orderId]);
    $group = $st->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        $grp = self::generateGroupToken();
        $cols = ['order_id', 'group_token', 'checkout_completed', 'paid_status_set_key', 'paid_status_key', 'source_channel', 'created_at'];
$vals = [':oid', ':gt', '0', ':ps_set', ':ps_key', ':sc', 'NOW()'];
$prm  = [
    ':oid' => $orderId,
    ':gt'  => $grp,
    ':ps_set' => 'group_paid_status',
    ':ps_key' => $this->getGroupPaidStatusKeyUnpaid(),
    ':sc' => $channel !== '' ? $channel : 'shop', // lub odziedziczone z ordera
];


        $sql = "INSERT INTO order_groups (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
        $ins = $this->pdo->prepare($sql);
        $ins->execute($prm);

        $group = [
            'id' => (int)$this->pdo->lastInsertId(),
            'group_token' => $grp,
        ];
    }

    return [
        'order_id'       => $orderId,
        'order_group_id' => (int)$group['id'],
        'checkout_token' => $orderCheckoutToken,
    ];
}


    /* ============================================================
     * ENUM KEYS (centralne punkty – w razie zmiany wystarczy tu)
     * ============================================================ */

    /** Zwraca klucze statusów traktowanych jako „otwarte” */
    private function getOpenOrderStatusKeys(): array
    {
        // Jeśli masz enum klasę, możesz ją tu użyć (OrderStatus::OPEN_PACKAGE_ADD_PRODUCTS->value, itd.)
        return ['open_package:add_products', 'open_package:payment_only', 'new'];
    }

    private function getOrderStatusKeyNew(): string
    {
        return 'new';
    }

    private function getGroupPaidStatusKeyUnpaid(): string
    {
        return 'unpaid';
    }

    /* ============================================================
     * UTILITIES
     * ============================================================ */

    /** Generator tokenu dla zamówienia: chk-xxxxxxxxxxxxxxxx */
    public static function generateCheckoutToken(): string
    {
        return 'chk-' . bin2hex(random_bytes(8));
    }

    /** Generator tokenu dla grupy: grp-xxxxxxxxxxxxxxxx */
    public static function generateGroupToken(): string
    {
        return 'grp-' . bin2hex(random_bytes(8));
    }

    /** Czy tabela ma kolumnę? (cache; bez prepared dla SHOW) */
    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '::' . $column;
        if (array_key_exists($key, $cache)) return $cache[$key];

        $col = $this->pdo->quote($column);
        $sql = "SHOW COLUMNS FROM `{$table}` LIKE {$col}";
        $st  = $this->pdo->query($sql);
        $cache[$key] = (bool)($st && $st->fetch(PDO::FETCH_ASSOC));
        return $cache[$key];
    }

    /** Miękkie logowanie */
    private function safeLog(string $level, string $channel, string $event, array $ctx = []): void
    {
        try {
            if (\function_exists('logg')) {
                logg($level, $channel, $event, $ctx);
            }
        } catch (Throwable $__) {
        }
    }
}
