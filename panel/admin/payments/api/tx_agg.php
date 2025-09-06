<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../bootstrap.php';
if (\session_status() !== \PHP_SESSION_ACTIVE) \session_start();

try {
    $ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
    $orderId = (int)($_GET['order_id'] ?? 0);

    if ($ownerId <= 0 || $orderId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_input']);
        exit;
    }

    if (!($pdo instanceof \PDO)) {
        throw new \RuntimeException('Missing PDO instance');
    }
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

    // items_total (pilnujemy ownera)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(COALESCE(oi.price, oi.unit_price, 0) * COALESCE(oi.quantity, oi.qty, 1)),0)
        FROM orders o
        JOIN order_groups og ON og.order_id = o.id
        JOIN order_items oi ON oi.order_group_id = og.id
        WHERE o.owner_id = :owner AND o.id = :oid
    ");
    $stmt->execute([':owner' => $ownerId, ':oid' => $orderId]);
    $itemsTotal = (float)($stmt->fetchColumn() ?: 0.0);

    // paid (z payment_transactions)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE
            WHEN status = 'zaksięgowana' AND transaction_type = 'wpłata' THEN amount_pln
            WHEN status = 'zaksięgowana' AND transaction_type = 'zwrot'  THEN -amount_pln
            ELSE 0 END),0) AS paid_pln
        FROM payment_transactions pt
        WHERE pt.owner_id = :owner
          AND (pt.order_id = :oid
               OR pt.order_group_id IN (
                    SELECT og2.id FROM orders o2
                    JOIN order_groups og2 ON og2.order_id = o2.id
                    WHERE o2.owner_id = :owner2 AND o2.id = :oid2
               ))
    ");
    $stmt->execute([':owner' => $ownerId, ':oid' => $orderId, ':owner2' => $ownerId, ':oid2' => $orderId]);
    $paid = (float)($stmt->fetchColumn() ?: 0.0);

    $itemsTotal = round($itemsTotal, 2);
    $paid       = round($paid, 2);
    $due        = max(0.0, round($itemsTotal - $paid, 2));

    echo json_encode([
        'ok'          => true,
        'order_id'    => $orderId,
        'items_total' => $itemsTotal,
        'paid'        => $paid,
        'due'         => $due,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    logg('error', 'payments.tx_agg', 'exception', ['msg' => $e->getMessage()]);
    if (!http_response_code() || http_response_code() === 200) {
        http_response_code(500);
    }
    echo json_encode(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()]);
}
