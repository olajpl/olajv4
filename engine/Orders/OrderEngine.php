<?php
// engine/Orders/OrderEngine.php — Olaj.pl V4 (ENUM-aware, group_token, owner-scope safe)
// Data: 2025-09-06
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
 * OrderEngine — kompatybilny z OLAJ_V4_ENUM, bezpieczny dla schematu:
 * - orders:  order_status_set_key='order_status', order_status_key IN (...)
 * - groups:  group_token (public handle), OPTIONAL owner_id (auto-detected)
 * - items:   source_type_set_key='order_item_source' (+ legacy columns tolerant)
 * - tokens:  orders.checkout_token (chk-...), order_groups.group_token (grp-...)
 */
final class OrderEngine
{
    public function __construct(private PDO $pdo) {}

    /* ======================================================================
     * PUBLIC API
     * ====================================================================== */

    /**
     * Wysoki poziom: dodaje pozycję. Silnik zapewnia order + open group.
     *
     * @param array{
     *   owner_id:int, client_id:int, product_id?:int|null,
     *   name:string, qty:float|int, unit_price:float|int, vat_rate:float|int, sku?:string,
     *   source_type?:string, channel?:string
     * } $payload
     * @return array{ok:bool, order_id?:int, order_group_id?:int, order_item_id?:int, checkout_token?:string, group_token?:string, reason?:string, message?:string, sql_state?:string, sql_code?:int|string, sql_msg?:string}
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
        $sourceType = (string)($payload['source_type'] ?? 'parser'); // enum key
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
            $this->safeLog('error', 'orderengine', 'addItem:invalid_payload', compact('ownerId','clientId') + ['payload'=>$payload]);
            return ['ok' => false, 'reason' => 'invalid_payload'];
        }

        try {
            $this->pdo->beginTransaction();

            // 1) Order + open group
            $og = $this->ensureOrderAndOpenGroup($ownerId, $clientId, $channel);
            $orderId       = (int)$og['order_id'];
            $groupId       = (int)$og['order_group_id'];
            $orderToken    = (string)$og['checkout_token'];
            $groupToken    = (string)$og['group_token'];

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

            // 2) INSERT do order_items (enum-aware) + wyliczenia
            $hasLegacySourceType = $this->columnExists('order_items', 'source_type');
            $hasSourceChannel    = $this->columnExists('order_items', 'source_channel');
            $hasChannel          = $this->columnExists('order_items', 'channel');
            $hasTotalPrice       = $this->columnExists('order_items', 'total_price');
            $hasVatValue         = $this->columnExists('order_items', 'vat_value');
            $hasCreatedAt        = $this->columnExists('order_items', 'created_at');
            $hasUpdatedAt        = $this->columnExists('order_items', 'updated_at');

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
            $this->recalcOrderShippingSafe($ownerId, $orderId);

            if (is_string($channel) && $channel !== '') {
                $this->updateOrderSourceChannel($orderId, $channel);
            }

            $this->safeLog('info', 'orderengine', 'addItem:ok', [
                'owner_id'       => $ownerId,
                'order_id'       => $orderId,
                'order_group_id' => $groupId,
                'order_item_id'  => $orderItemId,
                'checkout_token' => $orderToken,
                'group_token'    => $groupToken,
            ]);

            return [
                'ok'             => true,
                'order_id'       => $orderId,
                'order_group_id' => $groupId,
                'order_item_id'  => $orderItemId,
                'checkout_token' => $orderToken,
                'group_token'    => $groupToken,
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
     * Alias pod panel: zapewnia/zwra­ca otwarte zamówienie dla klienta.
     * Zwraca: ['id'=>int,'checkout_token'=>string]
     */
    public function findOrCreateOpenOrderForClient(int $ownerId, int $clientId, string $channel = 'panel'): array
    {
        $order = $this->findOpenOrder($ownerId, $clientId);
        if ($order) return $order;

        $chk = self::generateCheckoutToken();
        db_exec_logged(
            $this->pdo,
            "INSERT INTO orders (
                owner_id, client_id,
                order_status_set_key, order_status_key,
                checkout_token, checkout_completed, source_channel,
                created_at, updated_at
            ) VALUES (
                :oid, :cid,
                'order_status', :key,
                :ct, 0, :ch,
                NOW(), NOW()
            )",
            [
                ':oid'=>$ownerId, ':cid'=>$clientId,
                ':key'=>$this->getOrderStatusKeyNew(),
                ':ct'=>$chk, ':ch'=>$channel
            ],
            ['channel'=>'orders.ensure','event'=>'order.insert_new','owner_id'=>$ownerId,'client_id'=>$clientId]
        );
        return ['id'=>(int)$this->pdo->lastInsertId(), 'checkout_token'=>$chk];
    }

    /**
     * Alias pod panel: zapewnia/zwra­ca otwartą grupę dla zamówienia.
     * Zwraca m.in. group_token.
     */
    public function findOrCreateOpenGroup(int $orderId): array
    {
        $group = db_fetch_logged(
            $this->pdo,
            "SELECT id, group_token
               FROM order_groups
              WHERE order_id=:oid
                AND (checkout_completed IS NULL OR checkout_completed=0)
              ORDER BY id DESC
              LIMIT 1",
            [':oid'=>$orderId],
            ['channel'=>'orders.ensure','event'=>'group.find_open','order_id'=>$orderId]
        );
        if ($group) return $group;

        $grp = self::generateGroupToken();
        if ($this->groupHasOwnerId()) {
            // backfill owner_id z orders podczas insertu
            db_exec_logged(
                $this->pdo,
                "INSERT INTO order_groups (owner_id, order_id, group_token, checkout_completed, paid_status_set_key, paid_status_key, created_at, updated_at)
                 SELECT o.owner_id, o.id, :gt, 0, 'group_paid_status', :ps, NOW(), NOW()
                   FROM orders o WHERE o.id=:oid",
                [':gt'=>$grp, ':ps'=>$this->getGroupPaidStatusKeyUnpaid(), ':oid'=>$orderId],
                ['channel'=>'orders.ensure','event'=>'group.insert_new','order_id'=>$orderId]
            );
        } else {
            db_exec_logged(
                $this->pdo,
                "INSERT INTO order_groups (order_id, group_token, checkout_completed, paid_status_set_key, paid_status_key, created_at, updated_at)
                 VALUES (:oid,:gt,0,'group_paid_status',:ps,NOW(),NOW())",
                [':oid'=>$orderId, ':gt'=>$grp, ':ps'=>$this->getGroupPaidStatusKeyUnpaid()],
                ['channel'=>'orders.ensure','event'=>'group.insert_new','order_id'=>$orderId]
            );
        }
        return ['id'=>(int)$this->pdo->lastInsertId(), 'group_token'=>$grp];
    }

    /**
     * Syntactic sugar: dodanie pozycji po „kodzie” produktu.
     * Rozpoznaje code/sku/ean/twelve_nc/name (fallback SQL, jeśli brak ProductEngine).
     */
    public function addOrderItemByCode(
        int $ownerId, int $orderId, int $groupId, string $code, float $qty,
        string $source='panel', ?int $actorId=null
    ): array {
        $code = trim($code);
        if ($code === '' || $qty <= 0) {
            return ['ok'=>false, 'reason'=>'invalid_code_or_qty'];
        }

        // spróbuj ProductEngine, jeśli istnieje
        $p = null;
        try {
            if (\class_exists('\\Engine\\Orders\\ProductEngine')) {
                $pe = new \Engine\Orders\ProductEngine($this->pdo);
                if (\method_exists($pe,'findByAnyCode')) {
                    $p = $pe->findByAnyCode($ownerId, $code);
                }
            }
        } catch (\Throwable $e) {
            $this->safeLog('warning','orderengine','addByCode:PE_fail',['err'=>$e->getMessage()]);
        }

        if (!$p) {
            $st = $this->pdo->prepare("
                SELECT id,name,unit_price,vat_rate,sku
                  FROM products
                 WHERE owner_id=:oid AND deleted_at IS NULL
                   AND (code=:c OR sku=:c OR ean=:c OR twelve_nc=:c OR name=:c)
                 LIMIT 1
            ");
            $st->execute([':oid'=>$ownerId, ':c'=>$code]);
            $p = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($p) {
            return $this->addOrderItem([
                'owner_id'=>$ownerId,
                'client_id'=>$this->getOrderClientId($orderId),
                'product_id'=>(int)$p['id'],
                'name'=>(string)$p['name'],
                'qty'=>$qty,
                'unit_price'=>(float)($p['unit_price'] ?? 0),
                'vat_rate'=>(float)($p['vat_rate'] ?? 23),
                'sku'=>(string)($p['sku'] ?? ''),
                'source_type'=>'manual',
                'channel'=>$source,
            ]);
        }

        // fallback: pozycja custom 0 zł
        $og = db_fetch_logged(
            $this->pdo,
            "SELECT o.owner_id, o.client_id FROM orders o WHERE o.id=:oid LIMIT 1",
            [':oid'=>$orderId],
            ['channel'=>'orders.items','event'=>'custom.lookup','order_id'=>$orderId]
        );
        if (!$og) return ['ok'=>false,'reason'=>'order_not_found'];

        return $this->addOrderItem([
            'owner_id'=>(int)$og['owner_id'],
            'client_id'=>(int)$og['client_id'],
            'name'=>$code,
            'qty'=>$qty,
            'unit_price'=>0,
            'vat_rate'=>23,
            'sku'=>'',
            'source_type'=>'manual',
            'channel'=>$source,
        ]);
    }

    /* ======================================================================
     * UPDATE / REMOVE / TOGGLE — (jak w Twojej wersji) — pozostawione bez zmian
     * ====================================================================== */

    public function updateOrderItem(int $ownerId, int $orderId, int $groupId, int $itemId, array $patch): array
    {
        // ... (bez zmian w logice; zostawiam z Twojej wersji)
        // [kod z Twojej wersji wklejony 1:1]
        // — Dla zwięzłości: tożsamy z wersją, którą wkleiłeś — nie skracam dalej.
        // >>> POCZĄTEK KOPII TWOJEJ METODY <<<
        $this->assertContext($ownerId, $orderId, $groupId);

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

        $qty        = max(0.0, round($qty, 3));
        $unitPrice  = max(0.0, round($unitPrice, 2));
        $totalNet   = round($qty * $unitPrice, 2);
        $vatValue   = round($totalNet * ($vatRate/100.0), 2);

        $prevPacked = (float)($it['packed_count'] ?? 0.0);
        $packedNew  = min($prevPacked, $qty);
        $isPrepared = (int)($qty > 0 && $packedNew >= $qty);

        $useTotal = !$this->columnIsGenerated('order_items','total_price');
        $useVat   = !$this->columnIsGenerated('order_items','vat_value');

        $this->pdo->beginTransaction();
        try {
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

            if ($useTotal) { $setParts[] = "total_price = :total_net"; $params[':total_net'] = $totalNet; }
            if ($useVat)   { $setParts[] = "vat_value = :vat_value";  $params[':vat_value'] = $vatValue; }

            $hasUpdatedAt = $this->columnExists('order_items', 'updated_at');
            $hasPackedAt  = $this->columnExists('order_items', 'packed_at');

            $setParts[] = "packed_count = :packed";
            $setParts[] = "is_prepared  = :is_prep_set";
            if ($hasPackedAt) $setParts[] = "packed_at    = CASE WHEN :is_prep_case=1 THEN COALESCE(packed_at, NOW()) ELSE NULL END";
            if ($hasUpdatedAt) $setParts[] = "updated_at   = NOW()";

            $params[':packed']       = $packedNew;
            $params[':is_prep_set']  = $isPrepared;
            $params[':is_prep_case'] = $isPrepared;
            $params[':iid']          = $itemId;
            $params[':gid']          = $groupId;
            $params[':own']          = $ownerId;

            $sql = "UPDATE order_items SET " . implode(', ', $setParts) . "
                    WHERE id=:iid AND order_group_id=:gid AND owner_id=:own";

            db_exec_logged(
                $this->pdo, $sql, $params,
                ['channel'=>'orders.items','event'=>'item.update','owner_id'=>$ownerId,'order_id'=>$orderId,'order_group_id'=>$groupId,'item_id'=>$itemId]
            );

            try {
                $rc = (int)$this->pdo->query("SELECT ROW_COUNT()")->fetchColumn();
                if ($rc === 0) {
                    $this->safeLog('warning','orderengine','updateOrderItem:no_rows',[
                        'order_id'=>$orderId,'group_id'=>$groupId,'item_id'=>$itemId
                    ]);
                }
            } catch (Throwable $__) {}

            $this->pdo->commit();
            $this->recalcOrderShippingSafe($ownerId, $orderId);
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
        // >>> KONIEC KOPII <<<
    }

    public function removeOrderItem(int $ownerId, int $orderId, int $groupId, int $itemId): array
    {
        // (Twoja wersja — bez zmian)
        $this->assertContext($ownerId, $orderId, $groupId);

        $hasDeletedAt = $this->columnExists('order_items', 'deleted_at');
        $hasUpdatedAt = $this->columnExists('order_items', 'updated_at');
        $hasIsDeleted = $this->columnExists('order_items', 'is_deleted');

        if ($hasDeletedAt) {
            $sql = "UPDATE order_items
                       SET deleted_at = NOW()" . ($hasUpdatedAt ? ", updated_at = NOW()" : "") . "
                     WHERE id = :iid AND order_group_id = :gid AND owner_id = :own";
            db_exec_logged(
                $this->pdo, $sql,
                [':iid'=>$itemId, ':gid'=>$groupId, ':own'=>$ownerId],
                ['channel'=>'orders.items', 'event'=>'item.soft_delete_deleted_at', 'owner_id'=>$ownerId, 'order_id'=>$orderId, 'order_group_id'=>$groupId, 'item_id'=>$itemId]
            );
        } elseif ($hasIsDeleted) {
            $sql = "UPDATE order_items
                       SET is_deleted = 1" . ($hasUpdatedAt ? ", updated_at = NOW()" : "") . "
                     WHERE id = :iid AND order_group_id = :gid AND owner_id = :own";
            db_exec_logged(
                $this->pdo, $sql,
                [':iid'=>$itemId, ':gid'=>$groupId, ':own'=>$ownerId],
                ['channel'=>'orders.items', 'event'=>'item.soft_delete_is_deleted', 'owner_id'=>$ownerId, 'order_id'=>$orderId, 'order_group_id'=>$groupId, 'item_id'=>$itemId]
            );
        } else {
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

    /* ======================================================================
     * CORE HELPERS (ENUM-aware)
     * ====================================================================== */

    /**
     * Zapewnia: zamówienie (orders) i otwartą grupę (order_groups).
     * Zwraca: order_id, order_group_id, checkout_token (order), group_token (group)
     *
     * @return array{order_id:int, order_group_id:int, checkout_token:string, group_token:string}
     */
    private function ensureOrderAndOpenGroup(int $ownerId, int $clientId, string $channel = ''): array
    {
        $openKeys = $this->getOpenOrderStatusKeys();

        // 1) znajdź istniejący order (ENUM engine)
        $ph = [];
        $params = [':oid' => $ownerId, ':cid' => $clientId, ':st' => 'order_status'];
        foreach ($openKeys as $i => $k) { $kk = ':k' . $i; $ph[] = $kk; $params[$kk] = $k; }
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
            $order = ['id'=>(int)$this->pdo->lastInsertId(), 'checkout_token'=>$chk];
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
            if ($this->groupHasOwnerId()) {
                db_exec_logged(
                    $this->pdo,
                    "INSERT INTO order_groups (owner_id, order_id, group_token, checkout_completed, paid_status_set_key, paid_status_key, created_at, updated_at)
                     SELECT o.owner_id, o.id, :gt, 0, 'group_paid_status', :ps, NOW(), NOW()
                       FROM orders o WHERE o.id=:oid",
                    [':gt'=>$grp, ':ps'=>$this->getGroupPaidStatusKeyUnpaid(), ':oid'=>$orderId],
                    ['channel'=>'orders.ensure','event'=>'group.insert_new','order_id'=>$orderId]
                );
            } else {
                db_exec_logged(
                    $this->pdo,
                    "INSERT INTO order_groups (order_id, group_token, checkout_completed, paid_status_set_key, paid_status_key, created_at, updated_at)
                     VALUES (:oid,:gt,0,'group_paid_status',:ps,NOW(),NOW())",
                    [':oid'=>$orderId, ':gt'=>$grp, ':ps'=>$this->getGroupPaidStatusKeyUnpaid()],
                    ['channel'=>'orders.ensure','event'=>'group.insert_new','order_id'=>$orderId]
                );
            }
            $group = ['id'=>(int)$this->pdo->lastInsertId(), 'group_token'=>$grp];
        }

        return [
            'order_id'       => $orderId,
            'order_group_id' => (int)$group['id'],
            'checkout_token' => $orderCheckoutToken,
            'group_token'    => (string)$group['group_token'],
        ];
    }

    /** Lista kluczy statusów traktowanych jako „otwarte” */
    private function getOpenOrderStatusKeys(): array { return ['open_package:add_products', 'open_package:payment_only', 'new']; }
    private function getOrderStatusKeyNew(): string  { return 'new'; }
    private function getGroupPaidStatusKeyUnpaid(): string { return 'unpaid'; }

    /* ======================================================================
     * UTILITIES
     * ====================================================================== */

    public static function generateCheckoutToken(): string { return 'chk-' . bin2hex(random_bytes(8)); }
    public static function generateGroupToken(): string    { return 'grp-' . bin2hex(random_bytes(8)); }

    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '::' . $column;
        if (array_key_exists($key, $cache)) return $cache[$key];
        $col = $this->pdo->quote($column);
        try {
            $st  = $this->pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$col}");
            $cache[$key] = (bool)($st && $st->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $__) { $cache[$key] = false; }
        return $cache[$key];
    }

    private function groupHasOwnerId(): bool
    {
        return $this->columnExists('order_groups','owner_id');
    }

    private function safeLog(string $level, string $channel, string $event, array $ctx = []): void
    {
        try { if (\function_exists('logg')) { logg($level, $channel, $event, $ctx); } } catch (Throwable $__) {}
    }

    /** Guard ownera i locka checkoutu — JOIN do orders (order_groups może nie mieć owner_id) */
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

    private function extractSqlError(\PDOException $e): array
    {
        $info = $e->errorInfo ?? null;
        $sqlstate = is_array($info) && isset($info[0]) && $info[0] !== null ? (string)$info[0] : (string)$e->getCode();
        $drvCode  = is_array($info) && isset($info[1]) ? $info[1] : null;
        $drvMsg   = is_array($info) && isset($info[2]) ? $info[2] : $e->getMessage();
        return [$sqlstate, $drvCode, $drvMsg];
    }

    private function columnIsGenerated(string $table, string $column): bool
    {
        static $genCache = [];
        $key = $table . '::' . $column;
        if (array_key_exists($key, $genCache)) return $genCache[$key];

        $col = $this->pdo->quote($column);
        try {
            $st = $this->pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$col}");
            $row = $st ? $st->fetch(\PDO::FETCH_ASSOC) : false;
            $extra = is_array($row) && isset($row['Extra']) ? strtolower((string)$row['Extra']) : '';
            return $genCache[$key] = (str_contains($extra, 'generated'));
        } catch (\Throwable $__) {
            return $genCache[$key] = false;
        }
    }

    /** Cichy wrapper dla recalc — niech UI nie wybucha przy braku tabel płatności. */
    public function recalcOrderShippingSafe(int $ownerId, int $orderId): void
    {
        try { $this->recalcOrderShipping($ownerId, $orderId); }
        catch (\Throwable $e) {
            $this->safeLog('warning','orderengine','recalcOrderShippingSafe:fail',[
                'order_id'=>$orderId,'err'=>$e->getMessage()
            ]);
        }
    }

    /* ======================================================================
     * SHIPPING — Twoje metody (recalc / snapshot / tableExists) zostają
     * ====================================================================== */

    // (Wklejone 1:1 z Twojej wersji: recalcOrderShipping, getOrderShippingSnapshot, tableExists)
    public function recalcOrderShipping(int $ownerId, int $orderId): array
    {
        // ... [Twoja wersja — bez zmian; została przeniesiona 1:1]
        // >>> POCZĄTEK fragmentu z Twojej wersji <<<
        $ord = db_fetch_logged(
            $this->pdo,
            "SELECT id, owner_id FROM orders WHERE id=:oid AND owner_id=:own LIMIT 1",
            [':oid'=>$orderId, ':own'=>$ownerId],
            ['channel'=>'orders.ship','event'=>'order.fetch','order_id'=>$orderId]
        );
        if (!$ord) throw new \RuntimeException('order not found or owner mismatch');

        $del = $this->columnExists('order_items','deleted_at') ? "AND (oi.deleted_at IS NULL)" : "";
        $items = db_fetch_logged($this->pdo, "
            SELECT COALESCE(SUM(oi.total_price),0) AS items_due
            FROM order_items oi
            JOIN order_groups og ON og.id = oi.order_group_id
            WHERE og.order_id = :oid $del
        ", [':oid'=>$orderId], ['channel'=>'orders.ship','event'=>'items.sum','order_id'=>$orderId]);
        $itemsDue = (float)($items['items_due'] ?? 0.0);

        $captured = 0.0;
        try {
            $hasPT = $this->tableExists('payment_transactions');
            if ($hasPT) {
                $row = db_fetch_logged(
                    $this->pdo,
                    "SELECT COALESCE(SUM(
                         CASE
                           WHEN transaction_type='wpłata' THEN net_pln
                           WHEN transaction_type='zwrot'  THEN -net_pln
                           ELSE 0
                         END
                       ),0) AS cap
                     FROM payment_transactions
                     WHERE order_id = :oid
                       AND status = 'zaksięgowana'",
                    [':oid'=>$orderId],
                    ['channel'=>'orders.ship','event'=>'pt.sum','order_id'=>$orderId]
                );
                $captured = (float)($row['cap'] ?? 0.0);
            } else {
                $row = db_fetch_logged(
                    $this->pdo,
                    "SELECT COALESCE(SUM(p.amount_received),0) AS cap
                     FROM payments p
                     WHERE p.order_id = :oid
                       AND (
                         (p.status_set_key='payment_status' AND p.status_key IN ('paid','captured'))
                         OR (p.status IN ('paid'))
                       )
                       AND (p.deleted_at IS NULL)",
                    [':oid'=>$orderId],
                    ['channel'=>'orders.ship','event'=>'payments.sum','order_id'=>$orderId]
                );
                $captured = (float)($row['cap'] ?? 0.0);
            }
        } catch (\Throwable $__) { $captured = 0.0; }

        $ship = db_fetch_logged($this->pdo, "
            SELECT COALESCE(SUM(sl.price),0) AS ship_due
            FROM shipping_labels sl
            WHERE sl.order_id = :oid AND (sl.deleted_at IS NULL)
        ", [':oid'=>$orderId], ['channel'=>'orders.ship','event'=>'labels.sum','order_id'=>$orderId]);
        $shipDue = (float)($ship['ship_due'] ?? 0.0);

        $shipCovered = max(0.0, $captured - $itemsDue);
        $setKey = 'group_paid_status';
        if ($shipDue <= 0.0) {
            $shipKey = 'paid';
        } elseif ($shipCovered + 0.01 >= $shipDue) {
            $shipKey = 'paid';
        } elseif ($shipCovered > 0.0) {
            $shipKey = 'partial';
        } else {
            $shipKey = 'unpaid';
        }

        $this->pdo->beginTransaction();
        try {
            $prev = db_fetch_logged($this->pdo, "
                SELECT shipping_paid_status_key, shipping_paid_at
                FROM orders WHERE id=:oid FOR UPDATE
            ", [':oid'=>$orderId], ['channel'=>'orders.ship','event'=>'read.prev','order_id'=>$orderId]);

            $was = $prev['shipping_paid_status_key'] ?? null;
            $paidAtSet = !empty($prev['shipping_paid_at']);

            $sql = "UPDATE orders
                       SET shipping_due = :due,
                           shipping_paid_status_set_key = :sk,
                           shipping_paid_status_key = :kk,
                           ".($shipKey==='paid' && $was!=='paid' && !$paidAtSet ? "shipping_paid_at = NOW()," : "")."
                           updated_at = NOW()
                     WHERE id = :oid";
            db_exec_logged($this->pdo, $sql,
                [':due'=>$shipDue, ':sk'=>$setKey, ':kk'=>$shipKey, ':oid'=>$orderId],
                ['channel'=>'orders.ship','event'=>'write.cache','order_id'=>$orderId]
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->safeLog('error','orderengine','recalcOrderShipping:ex',[
                'order_id'=>$orderId,'err'=>$e->getMessage()
            ]);
            throw $e;
        }

        return [
            'ok'=>true,
            'order_id'=>$orderId,
            'items_due'=>round($itemsDue,2),
            'captured'=>round($captured,2),
            'shipping_due'=>round($shipDue,2),
            'shipping_paid_status_key'=>$shipKey
        ];
        // >>> KONIEC fragmentu <<<
    }

    public function getOrderShippingSnapshot(int $ownerId, int $orderId): array
    {
        // (Twoja wersja — 1:1)
        $ord = db_fetch_logged(
            $this->pdo,
            "SELECT id FROM orders WHERE id=:oid AND owner_id=:own LIMIT 1",
            [':oid'=>$orderId, ':own'=>$ownerId],
            ['channel'=>'orders.ship','event'=>'snapshot.order.fetch','order_id'=>$orderId]
        );
        if (!$ord) { throw new \RuntimeException('order not found or owner mismatch'); }

        $del = $this->columnExists('order_items','deleted_at') ? "AND (oi.deleted_at IS NULL)" : "";
        $items = db_fetch_logged($this->pdo, "
            SELECT COALESCE(SUM(oi.total_price),0) AS items_due
            FROM order_items oi
            JOIN order_groups og ON og.id = oi.order_group_id
            WHERE og.order_id = :oid $del
        ", [':oid'=>$orderId], ['channel'=>'orders.ship','event'=>'snapshot.items.sum','order_id'=>$orderId]);
        $itemsDue = (float)($items['items_due'] ?? 0.0);

        $captured = 0.0;
        try {
            $hasPT = $this->tableExists('payment_transactions');
            if ($hasPT) {
                $row = db_fetch_logged($this->pdo, "
                    SELECT COALESCE(SUM(
                        CASE WHEN transaction_type='wpłata' THEN net_pln
                             WHEN transaction_type='zwrot'  THEN -net_pln
                             ELSE 0 END
                    ),0) AS cap
                    FROM payment_transactions
                    WHERE order_id = :oid AND status = 'zaksięgowana'
                ", [':oid'=>$orderId], ['channel'=>'orders.ship','event'=>'snapshot.pt.sum','order_id'=>$orderId]);
                $captured = (float)($row['cap'] ?? 0.0);
            } else {
                $row = db_fetch_logged($this->pdo, "
                    SELECT COALESCE(SUM(p.amount_received),0) AS cap
                    FROM payments p
                    WHERE p.order_id = :oid
                      AND ( (p.status_set_key='payment_status' AND p.status_key IN ('paid','captured'))
                            OR p.status IN ('paid') )
                      AND (p.deleted_at IS NULL)
                ", [':oid'=>$orderId], ['channel'=>'orders.ship','event'=>'snapshot.payments.sum','order_id'=>$orderId]);
                $captured = (float)($row['cap'] ?? 0.0);
            }
        } catch (\Throwable $__) { $captured = 0.0; }

        $ship = db_fetch_logged($this->pdo, "
            SELECT COALESCE(SUM(sl.price),0) AS ship_due
            FROM shipping_labels sl
            WHERE sl.order_id = :oid AND (sl.deleted_at IS NULL)
        ", [':oid'=>$orderId], ['channel'=>'orders.ship','event'=>'snapshot.labels.sum','order_id'=>$orderId]);
        $shipDue = (float)($ship['ship_due'] ?? 0.0);

        $shipCovered = max(0.0, $captured - $itemsDue);
        $key = ($shipDue <= 0.0) ? 'paid'
             : (($shipCovered + 0.01 >= $shipDue) ? 'paid'
             : ($shipCovered > 0.0 ? 'partial' : 'unpaid'));

        return [
            'ok' => true,
            'order_id' => $orderId,
            'items_due' => round($itemsDue,2),
            'captured'  => round($captured,2),
            'shipping_due' => round($shipDue,2),
            'shipping_covered' => round($shipCovered,2),
            'shipping_paid_status_key' => $key,
        ];
    }

    private function tableExists(string $table): bool
    {
        try {
            $q = $this->pdo->query("SHOW TABLES LIKE " . $this->pdo->quote($table));
            return (bool)$q->fetchColumn();
        } catch (\Throwable $__) { return false; }
    }

    /* ======================================================================
     * PRIVATE HELPERS
     * ====================================================================== */

    /** Znajdź otwarty order (bez tworzenia) */
    private function findOpenOrder(int $ownerId, int $clientId): ?array
    {
        $open = $this->getOpenOrderStatusKeys();
        $ph = []; $params = [':oid'=>$ownerId, ':cid'=>$clientId, ':st'=>'order_status'];
        foreach ($open as $i=>$k) { $ph[]=':k'.$i; $params[':k'.$i]=$k; }
        $in = implode(',', $ph);

        $row = db_fetch_logged(
            $this->pdo,
            "SELECT id, checkout_token
               FROM orders
              WHERE owner_id=:oid AND client_id=:cid
                AND order_status_set_key=:st
                AND order_status_key IN ($in)
              ORDER BY id DESC
              LIMIT 1",
            $params,
            ['channel'=>'orders.ensure','event'=>'order.find_open_once','owner_id'=>$ownerId,'client_id'=>$clientId]
        );
        return $row ?: null;
    }

    /** Pobierz client_id z orders */
    private function getOrderClientId(int $orderId): int
    {
        $r = db_fetch_logged(
            $this->pdo,
            "SELECT client_id FROM orders WHERE id=:id LIMIT 1",
            [':id'=>$orderId],
            ['channel'=>'orders.ensure','event'=>'order.client_id','order_id'=>$orderId]
        );
        return (int)($r['client_id'] ?? 0);
    }

    /* ====================================================================== */
}
