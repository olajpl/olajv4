<?php
// engine/Orders/OrderEngine.php — Olaj.pl V4 (ENUM Engine edition, DB-guarded)
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
                'client_id'=> $clientId,
                'payload'  => $payload
            ]);
            return ['ok' => false, 'reason' => 'invalid_payload'];
        }

        try {
            $this->pdo->beginTransaction();

            // 1) Order + open group (przez guard)
            $og = $this->ensureOrderAndOpenGroup($ownerId, $clientId, $channel);
            $orderId       = (int)$og['order_id'];
            $groupId       = (int)$og['order_group_id'];
            $checkoutToken = (string)$og['checkout_token'];

            // sanity: grupa należy do ordera
            $chk = db_fetch_logged(
                $this->pdo,
                "SELECT 1 AS ok FROM order_groups WHERE id = :gid AND order_id = :oid LIMIT 1",
                [':gid' => $groupId, ':oid' => $orderId],
                ['channel'=>'orders.guard', 'event'=>'group.belongs', 'owner_id'=>$ownerId]
            );
            if (!$chk) {
                throw new RuntimeException("order_group mismatch or missing (order_id={$orderId}, group_id={$groupId})");
            }

            // 2) INSERT do order_items (enum-aware) + wyliczenia netto/VAT
            $hasLegacySourceType = $this->columnExists('order_items', 'source_type');
            $hasSourceChannel    = $this->columnExists('order_items', 'source_channel');
            $hasChannel          = $this->columnExists('order_items', 'channel');
            $hasTotalPrice       = $this->columnExists('order_items', 'total_price');
            $hasVatValue         = $this->columnExists('order_items', 'vat_value');
            $hasCreatedAt        = $this->columnExists('order_items', 'created_at');
            $hasUpdatedAt        = $this->columnExists('order_items', 'updated_at');

            // NEW: czy kolumny są GENERATED?
            $isTPGen = $hasTotalPrice && $this->columnIsGenerated('order_items', 'total_price');
            $isVVGen = $hasVatValue   && $this->columnIsGenerated('order_items', 'vat_value');

            $qty       = max(0.0, round($qty, 3));
            $unitPrice = max(0.0, round($unitPrice, 2));
            $totalNet  = round($qty * $unitPrice, 2);
            $vatValue  = round($totalNet * ($vatRate / 100.0), 2);

            $cols = [
                'owner_id','order_id','order_group_id','product_id','name',
                'qty','unit_price','vat_rate','sku',
                'source_type_set_key','source_type_key'
            ];
            $vals = [
                ':owner_id',':order_id',':order_group_id',':product_id',':name',
                ':qty',':unit_price',':vat_rate',':sku',
                ':st_set',':st_key'
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
                $cols[] = 'source_type'; $vals[] = ':source_type';
                $params[':source_type'] = $sourceType;
            }
            if ($hasSourceChannel && $channel !== '') {
                $cols[] = 'source_channel'; $vals[] = ':source_channel';
                $params[':source_channel'] = $channel;
            }
            if ($hasChannel && $channel !== '') {
                $cols[] = 'channel'; $vals[] = ':channel';
                $params[':channel'] = $channel;
            }
            // NEW: wstaw total_price / vat_value tylko, jeśli kolumny NIE są GENERATED
            if ($hasTotalPrice && !$isTPGen) {
                $cols[] = 'total_price'; $vals[] = ':total_price';
                $params[':total_price'] = $totalNet;
            }
            if ($hasVatValue && !$isVVGen) {
                $cols[] = 'vat_value'; $vals[] = ':vat_value';
                $params[':vat_value'] = $vatValue;
            }
            if ($hasCreatedAt) { $cols[] = 'created_at'; $vals[] = 'NOW()'; }
            if ($hasUpdatedAt) { $cols[] = 'updated_at'; $vals[] = 'NOW()'; }

            $sql = "INSERT INTO order_items (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
            db_exec_logged(
                $this->pdo,
                $sql,
                $params,
                ['channel'=>'orders.items', 'event'=>'item.insert', 'owner_id'=>$ownerId, 'order_id'=>$orderId, 'order_group_id'=>$groupId]
            );
            $orderItemId = (int)$this->pdo->lastInsertId();

            $this->pdo->commit();

            // po commit — ewentualna aktualizacja kanału zamówienia
            if (is_string($channel) && $channel !== '') {
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
            [$sqlstate,$drvCode,$drvMsg] = $this->extractSqlError($e);
            $this->safeLog('error', 'orderengine', 'addItem:pdo_ex', [
                'owner_id'    => $ownerId,
                'client_id'   => $clientId,
                'sqlstate'    => $sqlstate,
                'driver_code' => $drvCode,
                'message'     => $drvMsg,
            ]);
            return [
                'ok'        => false,
                'reason'    => 'sql_exception',
                'sql_state' => $sqlstate,
                'sql_code'  => $drvCode,
                'sql_msg'   => $drvMsg,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->safeLog('error', 'orderengine', 'addItem:ex', [
                'owner_id'  => $ownerId,
                'client_id' => $clientId,
                'err'       => $e->getMessage()
            ]);
            return ['ok' => false, 'reason' => 'exception', 'message' => $e->getMessage()];
        }
    }

    /**
     * Aktualizacja pozycji (dowolny podzbiór: qty / unit_price / vat_rate).
     * Dba o spójność packed_count/is_prepared i przelicza NETTO/VAT.
     */
    public function updateOrderItem(int $ownerId, int $orderId, int $groupId, int $itemId, array $patch): array
    {
        // walidacja powiązań i blokady
        $this->assertContext($ownerId, $orderId, $groupId);

        // pobierz item
        $it = db_fetch_logged(
            $this->pdo,
            "SELECT id, qty, unit_price, vat_rate, packed_count
               FROM order_items
              WHERE id=:iid AND order_group_id=:gid AND owner_id=:own
              LIMIT 1",
            [':iid'=>$itemId, ':gid'=>$groupId, ':own'=>$ownerId],
            ['channel'=>'orders.items', 'event'=>'item.fetch', 'owner_id'=>$ownerId, 'order_id'=>$orderId, 'order_group_id'=>$groupId]
        );
        if (!$it) throw new RuntimeException('item not found');

        $qty       = array_key_exists('qty', $patch)        ? (float)$patch['qty']        : (float)$it['qty'];
        $unitPrice = array_key_exists('unit_price', $patch) ? (float)$patch['unit_price'] : (float)$it['unit_price'];
        $vatRate   = array_key_exists('vat_rate', $patch)   ? (float)$patch['vat_rate']   : (float)$it['vat_rate'];

        // przeliczenia (zgodne z DDL: DECIMAL(8,3)/(10,2))
        $qty        = max(0.0, round($qty, 3));
        $unitPrice  = max(0.0, round($unitPrice, 2));
        $totalNet   = round($qty * $unitPrice, 2);
        $vatValue   = round($totalNet * ($vatRate/100.0), 2);

        $prevPacked = (float)($it['packed_count'] ?? 0.0);
        $packedNew  = min($prevPacked, $qty); // nie przekraczaj qty
        $isPrepared = (int)($qty > 0 && $packedNew >= $qty);

        // które kolumny są GENERATED?
        $useTotal = !$this->columnIsGenerated('order_items','total_price');
        $useVat   = !$this->columnIsGenerated('order_items','vat_value');

        $this->pdo->beginTransaction();
        try {
            // zbuduj SET i PARAMS w parze (żeby nie było HY093)
            $setParts = [
                "qty = :qty",
                "unit_price = :unit_price",
                "vat_rate = :vat_rate",
            ];
            $params = [
                ':qty'        => $qty,
                ':unit_price' => $unitPrice,
                ':vat_rate'   => $vatRate,
            ];

            if ($useTotal) {
                $setParts[]            = "total_price = :total_net";
                $params[':total_net']  = $totalNet;
            }
            if ($useVat) {
                $setParts[]            = "vat_value = :vat_value";
                $params[':vat_value']  = $vatValue;
            }

            $setParts[] = "packed_count = :packed";
            $setParts[] = "is_prepared  = :is_prep_set";
            $setParts[] = "packed_at    = CASE WHEN :is_prep_case=1 THEN COALESCE(packed_at, NOW()) ELSE NULL END";
            $setParts[] = "updated_at   = NOW()";

            $params[':packed']       = $packedNew;
            $params[':is_prep_set']  = $isPrepared;
            $params[':is_prep_case'] = $isPrepared;
            $params[':iid']          = $itemId;
            $params[':gid']          = $groupId;
            $params[':own']          = $ownerId;

            $sql = "UPDATE order_items SET " . implode(', ', $setParts) . "
                    WHERE id=:iid AND order_group_id=:gid AND owner_id=:own";

            db_exec_logged(
                $this->pdo,
                $sql,
                $params,
                ['channel'=>'orders.items','event'=>'item.update','owner_id'=>$ownerId,'order_id'=>$orderId,'order_group_id'=>$groupId,'item_id'=>$itemId]
            );

            // NEW: miękka diagnostyka – czy coś się faktycznie zmieniło?
            try {
                $rc = (int)$this->pdo->query("SELECT ROW_COUNT()")->fetchColumn();
                if ($rc === 0) {
                    $this->safeLog('warning','orderengine','updateOrderItem:no_rows',[
                        'order_id'=>$orderId,'group_id'=>$groupId,'item_id'=>$itemId
                    ]);
                }
            } catch (Throwable $__) {}

            $this->pdo->commit();

            $this->safeLog('info','orderengine','updateOrderItem:ok',[
                'order_id'=>$orderId,'group_id'=>$groupId,'item_id'=>$itemId
            ]);

            return [
                'ok'=>true,
                'item_id'=>$itemId,
                'qty'=>$qty,
                'unit_price'=>$unitPrice,
                'vat_rate'=>$vatRate,
                'total_price'=>$totalNet,
                'vat_value'=>$vatValue,
                'is_prepared'=>$isPrepared
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->safeLog('error','orderengine','updateOrderItem:ex',[
                'order_id'=>$orderId,'group_id'=>$groupId,'item_id'=>$itemId,'err'=>$e->getMessage()
            ]);
            throw $e;
        }
    }

    /** Soft-delete pozycji (deleted_at) */
    public function removeOrderItem(int $ownerId, int $orderId, int $groupId, int $itemId): array
    {
        $this->assertContext($ownerId, $orderId, $groupId);

        $hasDeletedAt = $this->columnExists('order_items', 'deleted_at');
        $hasUpdatedAt = $this->columnExists('order_items', 'updated_at');
        $hasIsDeleted = $this->columnExists('order_items', 'is_deleted');

        if ($hasDeletedAt) {
            // Soft-delete z deleted_at (+ updated_at jeśli jest)
            $sql = "UPDATE order_items
                       SET deleted_at = NOW()" . ($hasUpdatedAt ? ", updated_at = NOW()" : "") . "
                     WHERE id = :iid AND order_group_id = :gid AND owner_id = :own";
            db_exec_logged(
                $this->pdo, $sql,
                [':iid'=>$itemId, ':gid'=>$groupId, ':own'=>$ownerId],
                ['channel'=>'orders.items', 'event'=>'item.soft_delete_deleted_at', 'owner_id'=>$ownerId, 'order_id'=>$orderId, 'order_group_id'=>$groupId, 'item_id'=>$itemId]
            );
        } elseif ($hasIsDeleted) {
            // Soft-delete przez is_deleted (+ updated_at jeśli jest)
            $sql = "UPDATE order_items
                       SET is_deleted = 1" . ($hasUpdatedAt ? ", updated_at = NOW()" : "") . "
                     WHERE id = :iid AND order_group_id = :gid AND owner_id = :own";
            db_exec_logged(
                $this->pdo, $sql,
                [':iid'=>$itemId, ':gid'=>$groupId, ':own'=>$ownerId],
                ['channel'=>'orders.items', 'event'=>'item.soft_delete_is_deleted', 'owner_id'=>$ownerId, 'order_id'=>$orderId, 'order_group_id'=>$groupId, 'item_id'=>$itemId]
            );
        } else {
            // Brak wsparcia soft-delete w schemacie → twarde usunięcie
            db_exec_logged(
                $this->pdo,
                "DELETE FROM order_items WHERE id=:iid AND order_group_id=:gid AND owner_id=:own",
                [':iid'=>$itemId, ':gid'=>$groupId, ':own'=>$ownerId],
                ['channel'=>'orders.items', 'event'=>'item.hard_delete', 'owner_id'=>$ownerId, 'order_id'=>$orderId, 'order_group_id'=>$groupId, 'item_id'=>$itemId]
            );
        }

        $this->safeLog('info','orderengine','removeOrderItem:ok',[
            'order_id'=>$orderId,'group_id'=>$groupId,'item_id'=>$itemId
        ]);
        return ['ok'=>true];
    }

    /**
     * Ustawia source_channel w zamówieniu, jeśli nie było ustawione lub jest 'shop' (domyślne).
     */
    private function updateOrderSourceChannel(int $orderId, string $channel): void
    {
        try {
            db_exec_logged(
                $this->pdo,
                "UPDATE orders SET source_channel = :ch WHERE id = :id AND (source_channel IS NULL OR source_channel = 'shop')",
                [':ch' => $channel, ':id' => $orderId],
                ['channel'=>'orders.source', 'event'=>'order.source_channel.update', 'order_id'=>$orderId]
            );

            // opcjonalnie możesz sprawdzić rowCount, ale guard i tak zaloguje SQL
            $this->safeLog('debug', 'orderengine', 'source_channel_updated', [
                'order_id' => $orderId,
                'channel'  => $channel
            ]);
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
     */
    public function updateOrderMeta(int $orderId, array $fields): bool
    {
        if ($orderId <= 0 || empty($fields)) {
            $this->safeLog('warning', 'orderengine', 'updateMeta:invalid', [
                'order_id' => $orderId,
                'fields'   => $fields
            ]);
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
                'order_id' => $orderId
            ]);
            return false;
        }

        $sql = "UPDATE orders SET " . implode(', ', $set) . ", updated_at = NOW() WHERE id = :id LIMIT 1";
        db_exec_logged(
            $this->pdo,
            $sql,
            $params,
            ['channel'=>'orders.meta', 'event'=>'order.update_meta', 'order_id'=>$orderId]
        );
        return true;
    }

    /* ============================================================
     * CORE HELPERS (ENUM-aware)
     * ============================================================ */

    /**
     * Zapewnia istnienie zamówienia (orders) i otwartej grupy (order_groups).
     * - orders:  order_status_set_key='order_status', order_status_key IN OPEN_SET
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

        $order = db_fetch_logged(
            $this->pdo,
            "
            SELECT id, checkout_token
              FROM orders
             WHERE owner_id = :oid
               AND client_id = :cid
               AND order_status_set_key = :st
               AND order_status_key IN ($in)
             ORDER BY id DESC
             LIMIT 1
            ",
            $params,
            ['channel'=>'orders.ensure', 'event'=>'order.find_open', 'owner_id'=>$ownerId, 'client_id'=>$clientId]
        );

        if (!$order) {
            // utwórz nowy order: status 'new'
            $chk = self::generateCheckoutToken();
            db_exec_logged(
                $this->pdo,
                "
                INSERT INTO orders (
                    owner_id, client_id,
                    order_status_set_key, order_status_key,
                    checkout_token, checkout_completed,
                    source_channel,
                    created_at, updated_at
                ) VALUES (
                    :oid, :cid,
                    'order_status', :key,
                    :ct, 0,
                    :ch,
                    NOW(), NOW()
                )
                ",
                [
                    ':oid' => $ownerId,
                    ':cid' => $clientId,
                    ':key' => $this->getOrderStatusKeyNew(),
                    ':ct'  => $chk,
                    ':ch'  => $channel,
                ],
                ['channel'=>'orders.ensure', 'event'=>'order.insert_new', 'owner_id'=>$ownerId, 'client_id'=>$clientId]
            );
            $order = [
                'id' => (int)$this->pdo->lastInsertId(),
                'checkout_token' => $chk,
            ];
        }

        $orderId = (int)$order['id'];
        $orderCheckoutToken = (string)$order['checkout_token'];

        // 2) znajdź/utwórz otwartą grupę
        $group = db_fetch_logged(
            $this->pdo,
            "
            SELECT id, group_token
              FROM order_groups
             WHERE order_id = :oid
               AND (checkout_completed IS NULL OR checkout_completed = 0)
             ORDER BY id DESC
             LIMIT 1
            ",
            [':oid' => $orderId],
            ['channel'=>'orders.ensure', 'event'=>'group.find_open', 'owner_id'=>$ownerId, 'order_id'=>$orderId]
        );

        if (!$group) {
            $grp = self::generateGroupToken();
            $cols = ['order_id','group_token','checkout_completed','paid_status_set_key','paid_status_key','source_channel','created_at','updated_at'];
            $vals = [':oid',':gt','0',':ps_set',':ps_key',':sc','NOW()','NOW()'];

            $prm  = [
                ':oid' => $orderId,
                ':gt'  => $grp,
                ':ps_set' => 'group_paid_status',
                ':ps_key' => $this->getGroupPaidStatusKeyUnpaid(),
                ':sc' => $channel !== '' ? $channel : 'shop', // lub odziedziczone z ordera
            ];

            $sql = "INSERT INTO order_groups (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
            db_exec_logged(
                $this->pdo,
                $sql,
                $prm,
                ['channel'=>'orders.ensure', 'event'=>'group.insert_new', 'owner_id'=>$ownerId, 'order_id'=>$orderId]
            );

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

        // SHOW COLUMNS też logujemy, ale lekko: to meta, nie błąd
        $col = $this->pdo->quote($column);
        $sql = "SHOW COLUMNS FROM `{$table}` LIKE {$col}";
        try {
            $st  = $this->pdo->query($sql);
            $cache[$key] = (bool)($st && $st->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $__) {
            $cache[$key] = false;
        }
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

    /** Walidacja kontekstu: order+group należą do ownera; opcjonalnie blokada checkoutu. */
    private function assertContext(int $ownerId, int $orderId, int $groupId, bool $blockWhenCheckoutCompleted = true): array
    {
        $row = db_fetch_logged(
            $this->pdo,
            "
            SELECT o.id AS order_id,
                   og.id AS group_id,
                   o.owner_id,
                   COALESCE(o.checkout_completed, 0) AS checkout_completed
              FROM orders o
              JOIN order_groups og ON og.order_id = o.id
             WHERE o.id = :oid
               AND og.id = :gid
               AND o.owner_id = :own
             LIMIT 1
            ",
            [':oid'=>$orderId, ':gid'=>$groupId, ':own'=>$ownerId],
            ['channel'=>'orders.guard', 'event'=>'assert.context', 'owner_id'=>$ownerId, 'order_id'=>$orderId, 'order_group_id'=>$groupId]
        );

        if (!$row) {
            $this->safeLog('warning','orderengine','assertContext:mismatch',[
                'owner_id'=>$ownerId,'order_id'=>$orderId,'group_id'=>$groupId
            ]);
            throw new RuntimeException('order/group mismatch or owner mismatch');
        }
        if ($blockWhenCheckoutCompleted && (int)$row['checkout_completed'] === 1) {
            $this->safeLog('warning','orderengine','assertContext:locked',[
                'order_id'=>$orderId,'group_id'=>$groupId
            ]);
            throw new RuntimeException('checkout locked (checkout_completed=1)');
        }
        return $row;
    }

    /** Pobranie pozycji z walidacją ownera i grupy (pomija soft-deleted). */
    private function getItem(int $ownerId, int $groupId, int $itemId): array
    {
        // NEW: filtr deleted_at tylko jeśli kolumna istnieje
        $deletedCond = $this->columnExists('order_items','deleted_at') ? "AND (deleted_at IS NULL)" : "";

        $it = db_fetch_logged(
            $this->pdo,
            "
            SELECT *
              FROM order_items
             WHERE id = :iid
               AND order_group_id = :gid
               AND owner_id = :own
               $deletedCond
             LIMIT 1
            ",
            [':iid'=>$itemId, ':gid'=>$groupId, ':own'=>$ownerId],
            ['channel'=>'orders.items', 'event'=>'item.get', 'owner_id'=>$ownerId, 'order_group_id'=>$groupId, 'item_id'=>$itemId]
        );
        if (!$it) {
            $this->safeLog('warning','orderengine','getItem:not_found',[
                'owner_id'=>$ownerId,'group_id'=>$groupId,'item_id'=>$itemId
            ]);
            throw new RuntimeException('item not found');
        }
        return $it;
    }

    /** Ekstrakcja info z PDOException (stabilna nawet dla HY093) */
    private function extractSqlError(\PDOException $e): array
    {
        $info = $e->errorInfo ?? null;
        $sqlstate = is_array($info) && isset($info[0]) && $info[0] !== null ? (string)$info[0] : (string)$e->getCode();
        $drvCode  = is_array($info) && isset($info[1]) ? $info[1] : null;
        $drvMsg   = is_array($info) && isset($info[2]) ? $info[2] : $e->getMessage();
        return [$sqlstate, $drvCode, $drvMsg];
    }

    /** Czy kolumna jest STORED/GENERATED? (cache; używa SHOW COLUMNS → Extra) */
    private function columnIsGenerated(string $table, string $column): bool
    {
        static $genCache = [];
        $key = $table . '::' . $column;
        if (array_key_exists($key, $genCache)) return $genCache[$key];

        $col = $this->pdo->quote($column);
        $sql = "SHOW COLUMNS FROM `{$table}` LIKE {$col}";
        try {
            $st = $this->pdo->query($sql);
            $row = $st ? $st->fetch(\PDO::FETCH_ASSOC) : false;
            $extra = is_array($row) && isset($row['Extra']) ? strtolower((string)$row['Extra']) : '';
            // MySQL zwraca np. "stored generated" albo "virtual generated"
            return $genCache[$key] = (str_contains($extra, 'generated'));
        } catch (\Throwable $__) {
            return $genCache[$key] = false;
        }
    }
}
