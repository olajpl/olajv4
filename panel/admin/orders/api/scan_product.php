<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$userId  = (int)($_SESSION['user']['id'] ?? 0);

// --- DIAGNOSTYKA ---
$DEBUG = (isset($_GET['debug']) && $_GET['debug'] === '1') || (isset($_SERVER['HTTP_X_DEBUG']) && $_SERVER['HTTP_X_DEBUG'] === '1');
$STEP  = 'start';

$logSafe = function (string $level, string $channel, string $msg, array $ctx = []): void {
    try {
        if (function_exists('logg')) {
            logg($level, $channel, $msg, $ctx);
        }
    } catch (Throwable $__) {
    }
};
function json_out(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function json_fail_diag(string $msg, string $step, bool $debug, ?Throwable $e = null, array $extra = []): void
{
    $base = ['ok' => false, 'error' => $debug && $e ? ($msg . ' [' . $e->getMessage() . ']') : $msg, 'step' => $step];
    if ($debug && $e instanceof PDOException) {
        $base['sqlstate'] = $e->getCode();
    }
    json_out($base + $extra);
}

try {
    // Wejście
    $STEP = 'parse-json';
    $raw = file_get_contents('php://input') ?: '{}';
    $in  = json_decode($raw, true);
    if (!is_array($in)) {
        json_out(['ok' => false, 'error' => 'Nieprawidłowy JSON payload.', 'step' => $STEP, 'raw' => $DEBUG ? $raw : null]);
    }

    $STEP = 'csrf';
    $csrf = (string)($_SERVER['HTTP_X_CSRF'] ?? ($in['csrf'] ?? ''));
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        json_out(['ok' => false, 'error' => 'Brak lub niepoprawny CSRF.', 'step' => $STEP]);
    }

    $STEP = 'inputs';
    $orderId = (int)($in['order_id'] ?? 0);
    $groupId = isset($in['group_id']) ? (int)$in['group_id'] : 0;
    $codeRaw = trim((string)($in['code'] ?? ''));
    $inc     = (int)($in['inc'] ?? 1);

    if ($ownerId <= 0) json_out(['ok' => false, 'error' => 'Brak owner_id w sesji.', 'step' => $STEP]);
    if ($orderId <= 0) json_out(['ok' => false, 'error' => 'Brak order_id.', 'step' => $STEP]);
    if ($codeRaw === '') json_out(['ok' => false, 'error' => 'Podaj kod (SKU/CODE/EAN/12NC).', 'step' => $STEP]);
    if ($inc <= 0) json_out(['ok' => false, 'error' => 'Nieprawidłowa inkrementacja (inc).', 'step' => $STEP]);

    $code = strtoupper($codeRaw);

    $STEP = 'auth-order';
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE id=:oid AND owner_id=:own LIMIT 1");
    $stmt->execute(['oid' => $orderId, 'own' => $ownerId]);
    if (!$stmt->fetch()) {
        json_out(['ok' => false, 'error' => 'Zamówienie nie istnieje lub brak dostępu.', 'step' => $STEP]);
    }

    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Throwable $__) {
    }

    $STEP = 'tx-begin';
    $pdo->beginTransaction();

    // 1) Szukanie po order_items.sku
    $STEP = 'find-item-by-sku';
    $bind = ['oid' => $orderId, 'own' => $ownerId, 'sku' => $code];
    if ($groupId > 0) {
        $bind['gid'] = $groupId;
    }

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
    $stmtIt = $pdo->prepare($sqlByItemSku);
    $stmtIt->execute($bind);
    $it = $stmtIt->fetch(PDO::FETCH_ASSOC);

    // 2) Jeśli brak — po produkcie (code/sku/ean/twelve_nc)
    if (!$it) {
        $STEP = 'find-item-by-product';
        $bind2 = ['oid' => $orderId, 'own' => $ownerId, 'code' => $code];
        if ($groupId > 0) $bind2['gid'] = $groupId;

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
        $stmtP = $pdo->prepare($sqlByProduct);
        $stmtP->execute($bind2);
        $it = $stmtP->fetch(PDO::FETCH_ASSOC);
    }

    if (!$it) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'Nie znaleziono pozycji po SKU/CODE/EAN/12NC albo wszystko już spakowane.', 'step' => $STEP]);
    }

    // 3) Update licznika
    $STEP = 'update-item';
    $itemId = (int)$it['id'];
    $qty    = (float)$it['qty'];
    $pc     = (int)$it['packed_count'];

    $newPc = $pc + $inc;
    if ($newPc > $qty) $newPc = (int)floor($qty);
    $done  = $newPc >= $qty;

    $upd = $pdo->prepare("
    UPDATE order_items
       SET packed_count = :pc,
           is_prepared  = CASE WHEN :pc >= qty THEN 1 ELSE is_prepared END,
           packed_at    = CASE WHEN :pc >= qty AND is_prepared = 0 THEN NOW() ELSE packed_at END,
           updated_at   = NOW()
     WHERE id = :id
     LIMIT 1
  ");
    $upd->execute(['pc' => $newPc, 'id' => $itemId]);

    $STEP = 'tx-commit';
    $pdo->commit();

    $logSafe('info', 'orders.scan', 'scan.ok', [
        'owner_id' => $ownerId,
        'order_id' => $orderId,
        'item_id' => $itemId,
        'inc' => $inc,
        'new_pc' => $newPc,
        'done' => $done
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
        try {
            $pdo->rollBack();
        } catch (Throwable $__) {
        }
    }
    $logSafe('error', 'orders.scan', 'scan.fail', ['step' => $STEP, 'err' => $e->getMessage()]);
    json_fail_diag('Błąd serwera', $STEP, $DEBUG, $e);
}
