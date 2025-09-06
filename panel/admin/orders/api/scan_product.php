<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';

if (session_status() === PHP_SESSION_NONE) session_start();

/** JSON helpery */
function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function json_fail_diag(string $msg, string $step, bool $debug, ?Throwable $e = null, array $extra = []): void {
    $base = ['ok' => false, 'error' => $msg, 'step' => $step];
    if ($debug && $e instanceof \PDOException) {
        $info = $e->errorInfo ?? null;
        $base['sql_state'] = $info[0] ?? (string)$e->getCode();
        $base['sql_code']  = $info[1] ?? null;
        $base['sql_msg']   = $info[2] ?? $e->getMessage();
    } elseif ($debug && $e) {
        $base['detail'] = $e->getMessage();
    }
    json_out($base + $extra, 400);
}
function log_safe(string $level, string $channel, string $event, array $ctx = []): void {
    try { if (function_exists('logg')) { logg($level, $channel, $event, $ctx); } } catch (Throwable $__) {}
}

$STEP  = 'start';
$DEBUG = (isset($_GET['debug']) && $_GET['debug'] === '1') || (($_SERVER['HTTP_X_DEBUG'] ?? '') === '1');

try {
    // ── Autoryzacja + CSRF ─────────────────────────────────────
    $STEP = 'auth';
    $ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
    $userId  = (int)($_SESSION['user']['id'] ?? 0);
    if ($ownerId <= 0) json_out(['ok' => false, 'error' => 'Unauthorized', 'step' => $STEP], 401);

    $STEP = 'csrf';
    $raw  = file_get_contents('php://input') ?: '{}';
    $in   = json_decode($raw, true);
    if (!is_array($in)) json_out(['ok'=>false,'error'=>'Nieprawidłowy JSON payload.','step'=>$STEP] + ($DEBUG ? ['raw'=>$raw] : []), 400);

    $csrf = (string)($_SERVER['HTTP_X_CSRF'] ?? ($in['csrf'] ?? ''));
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        json_out(['ok'=>false,'error'=>'Brak lub niepoprawny CSRF.','step'=>$STEP], 403);
    }

    // ── Wejście ────────────────────────────────────────────────
    $STEP    = 'inputs';
    $orderId = (int)($in['order_id'] ?? 0);
    $groupId = isset($in['group_id']) ? (int)$in['group_id'] : 0;
    $codeRaw = trim((string)($in['code'] ?? ''));
    $inc     = (int)($in['inc'] ?? 1);

    if ($orderId <= 0) json_out(['ok'=>false,'error'=>'Brak order_id.','step'=>$STEP], 400);
    if ($codeRaw === '') json_out(['ok'=>false,'error'=>'Podaj kod (SKU/CODE/EAN/12NC).','step'=>$STEP], 400);
    if ($inc <= 0) json_out(['ok'=>false,'error'=>'Nieprawidłowa inkrementacja (inc).','step'=>$STEP], 400);

    $code = strtoupper($codeRaw);

    // ── Uprawnienia do zamówienia ─────────────────────────────
    $STEP = 'auth-order';
    db_fetch_logged(
        $pdo,
        "SELECT id FROM orders WHERE id = :oid AND owner_id = :own LIMIT 1",
        [':oid'=>$orderId, ':own'=>$ownerId],
        ['channel'=>'orders.scan','event'=>'order.auth','owner_id'=>$ownerId,'order_id'=>$orderId]
    ) ?: json_out(['ok'=>false,'error'=>'Zamówienie nie istnieje lub brak dostępu.','step'=>$STEP], 404);

    // ── Transakcja ─────────────────────────────────────────────
    $STEP = 'tx-begin';
    $pdo->beginTransaction();

    // 1) Szukaj po order_items.sku
    $STEP = 'find-item-by-sku';
    $bind = [':oid'=>$orderId, ':own'=>$ownerId, ':sku'=>$code];
    $sqlByItemSku = "
        SELECT oi.id, oi.order_group_id, oi.qty, oi.packed_count, oi.sku, oi.name
          FROM order_items oi
          JOIN order_groups og ON og.id = oi.order_group_id
         WHERE og.order_id = :oid
           AND oi.owner_id = :own
           " . ($groupId > 0 ? "AND oi.order_group_id = :gid" : "") . "
           AND UPPER(oi.sku) = :sku
           AND oi.packed_count < oi.qty
         ORDER BY oi.created_at ASC, oi.id ASC
         FOR UPDATE
    ";
    if ($groupId > 0) { $bind[':gid'] = $groupId; }
    $it = db_fetch_logged(
        $pdo,
        $sqlByItemSku,
        $bind,
        ['channel'=>'orders.scan','event'=>'find.by_sku','owner_id'=>$ownerId,'order_id'=>$orderId,'group_id'=>$groupId ?: null,'code'=>$code]
    );

    // 2) Jeśli brak — szukaj po produkcie (code/sku/ean/twelve_nc)
    if (!$it) {
        $STEP = 'find-item-by-product';
        $bind2 = [':oid'=>$orderId, ':own'=>$ownerId, ':code'=>$code];
        $sqlByProduct = "
            SELECT oi.id, oi.order_group_id, oi.qty, oi.packed_count, oi.sku, oi.name
              FROM order_items oi
              JOIN order_groups og ON og.id = oi.order_group_id
              JOIN products p     ON p.id = oi.product_id
             WHERE og.order_id = :oid
               AND oi.owner_id = :own
               AND p.owner_id  = :own
               AND p.is_active = 1
               AND p.active    = 1
               AND p.deleted_at IS NULL
               " . ($groupId > 0 ? "AND oi.order_group_id = :gid" : "") . "
               AND (
                   UPPER(p.code)      = :code
                OR UPPER(p.sku)       = :code
                OR UPPER(p.ean)       = :code
                OR UPPER(p.twelve_nc) = :code
               )
               AND oi.packed_count < oi.qty
             ORDER BY oi.created_at ASC, oi.id ASC
             FOR UPDATE
        ";
        if ($groupId > 0) { $bind2[':gid'] = $groupId; }
        $it = db_fetch_logged(
            $pdo,
            $sqlByProduct,
            $bind2,
            ['channel'=>'orders.scan','event'=>'find.by_product','owner_id'=>$ownerId,'order_id'=>$orderId,'group_id'=>$groupId ?: null,'code'=>$code]
        );
    }

    if (!$it) {
        $pdo->rollBack();
        json_out(['ok'=>false,'error'=>'Nie znaleziono pozycji po SKU/CODE/EAN/12NC albo wszystko już spakowane.','step'=>$STEP], 404);
    }

    // 3) Aktualizacja licznika (unikalne placeholdery → brak HY093)
    $STEP  = 'update-item';
    $itemId = (int)$it['id'];
    $qty    = (float)$it['qty'];
    $pc     = (int)$it['packed_count'];

    $newPc  = $pc + $inc;
    if ($newPc > $qty) $newPc = (int)floor($qty);
    $done   = $newPc >= $qty;

    db_exec_logged(
        $pdo,
        "
        UPDATE order_items
           SET packed_count = :pc_set,
               is_prepared  = CASE WHEN :pc_cmp1 >= qty THEN 1 ELSE is_prepared END,
               packed_at    = CASE WHEN :pc_cmp2 >= qty AND is_prepared = 0 THEN NOW() ELSE packed_at END,
               updated_at   = NOW()
         WHERE id = :id
         LIMIT 1
        ",
        [
            ':pc_set'  => $newPc,
            ':pc_cmp1' => $newPc,
            ':pc_cmp2' => $newPc,
            ':id'      => $itemId,
        ],
        ['channel'=>'orders.scan','event'=>'item.update','owner_id'=>$ownerId,'order_id'=>$orderId,'item_id'=>$itemId,'inc'=>$inc,'new_pc'=>$newPc,'done'=>$done]
    );

    $STEP = 'tx-commit';
    $pdo->commit();

    log_safe('info', 'orders.scan', 'scan.ok', [
        'owner_id'=>$ownerId,'user_id'=>$userId,'order_id'=>$orderId,
        'item_id'=>$itemId,'inc'=>$inc,'new_pc'=>$newPc,'done'=>$done
    ]);

    json_out([
        'ok'   => true,
        'item' => [
            'id'           => $itemId,
            'sku'          => $it['sku'],
            'name'         => $it['name'],
            'qty'          => (float)$qty,
            'packed_count' => $newPc,
            'done'         => $done
        ]
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $__) {}
    }
    log_safe('error','orders.scan','scan.fail',['step'=>$STEP,'err'=>$e->getMessage()]);
    json_fail_diag('Błąd serwera', $STEP, $DEBUG, $e);
}
