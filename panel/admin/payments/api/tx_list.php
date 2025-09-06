<?php
// admin/payments/api/tx_list.php — V4: UNION pt + payments, zero HY093 (unikalne placeholdery)
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../bootstrap.php';
require_once __DIR__ . '/../../../../includes/log.php';

if (\session_status() !== \PHP_SESSION_ACTIVE) \session_start();

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$orderId = (int)($_GET['order_id'] ?? 0);
$groupId = (int)($_GET['group_id'] ?? 0);

if ($ownerId <= 0 || ($orderId <= 0 && $groupId <= 0)) {
    echo json_encode(['ok' => false, 'error' => 'missing_parameters']); exit;
}

try {
    /** @var PDO $pdo */
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $byGroup = $groupId > 0;

    // 1) payment_transactions → już w „języku” frontu
    $ptWhere = $byGroup
        ? "pt.owner_id = :own_a AND pt.order_group_id = :id_a"
        : "pt.owner_id = :own_a AND pt.order_id       = :id_a";

    $sqlPT = "
        SELECT
            pt.id,
            pt.transaction_type,
            pt.status,
            pt.amount,
            pt.currency,
            pt.amount_pln,
            pt.net_pln,
            pt.method,
            pt.provider,
            pt.provider_tx_id,
            pt.created_at,
            pt.transaction_date,
            pt.booked_at
        FROM payment_transactions pt
        WHERE $ptWhere
    ";

    $argsPT = [
        'own_a' => $ownerId,
        'id_a'  => $byGroup ? $groupId : $orderId,
    ];

    // 2) payments → zmapowane pod front; bez duplikatów względem PT
    $pmWhere = $byGroup
        ? "p.owner_id = :own_b AND p.order_group_id = :id_b"
        : "p.owner_id = :own_b AND p.order_id       = :id_b";

    $sqlPM = "
        SELECT
            p.id,
            'wpłata' AS transaction_type,
            CASE p.status
                WHEN 'paid'      THEN 'zaksięgowana'
                WHEN 'failed'    THEN 'odrzucona'
                WHEN 'cancelled' THEN 'anulowana'
                ELSE 'oczekująca'
            END AS status,
            p.amount,
            p.currency,
            CASE WHEN p.currency='PLN' THEN p.amount ELSE NULL END AS amount_pln,
            NULL AS net_pln,
            p.method,
            p.provider,
            p.provider_payment_id AS provider_tx_id,
            p.created_at,
            COALESCE(p.paid_at, p.created_at) AS transaction_date,
            p.paid_at AS booked_at
        FROM payments p
        WHERE $pmWhere
          AND NOT EXISTS (
                SELECT 1
                FROM payment_transactions pt2
                WHERE pt2.owner_id = p.owner_id
                  AND pt2.payment_id = p.id
          )
    ";

    $argsPM = [
        'own_b' => $ownerId,
        'id_b'  => $byGroup ? $groupId : $orderId,
    ];

    // UNION (paramy podajemy jako merge dwóch zestawów)
    $sqlUnion = "
        SELECT * FROM (
            $sqlPT
            UNION ALL
            $sqlPM
        ) t
        ORDER BY t.transaction_date DESC, t.id DESC
        LIMIT 200
    ";
    $st = $pdo->prepare($sqlUnion);
    $st->execute($argsPT + $argsPM);
    $transactions = $st->fetchAll();

    // 3) Suma pozycji (order_items → order_groups → orders z owner guard)
    if ($byGroup) {
        $sqlItems = "
            SELECT COALESCE(SUM(oi.qty * oi.unit_price),0) AS total
            FROM order_items oi
            JOIN order_groups og ON og.id = oi.order_group_id
            JOIN orders o        ON o.id  = og.order_id
            WHERE o.owner_id = :own_i AND og.id = :id_i
        ";
        $argsItems = ['own_i' => $ownerId, 'id_i' => $groupId];
    } else {
        $sqlItems = "
            SELECT COALESCE(SUM(oi.qty * oi.unit_price),0) AS total
            FROM order_items oi
            JOIN order_groups og ON og.id = oi.order_group_id
            JOIN orders o        ON o.id  = og.order_id
            WHERE o.owner_id = :own_i AND og.order_id = :id_i
        ";
        $argsItems = ['own_i' => $ownerId, 'id_i' => $orderId];
    }
    $stIt = $pdo->prepare($sqlItems);
    $stIt->execute($argsItems);
    $items_total = (float)($stIt->fetchColumn() ?: 0.0);

    // 4) Suma wpłat PLN + ostatnia płatność (źródło: payments)
    $sqlPaid = "
        SELECT
            COALESCE(SUM(CASE WHEN p.currency='PLN' AND p.status='paid' THEN p.amount ELSE 0 END),0) AS paid_pln,
            MAX(p.paid_at) AS last_paid_at
        FROM payments p
        WHERE $pmWhere
    ";
    $stPaid = $pdo->prepare($sqlPaid);
    $stPaid->execute($argsPM);
    $paidRow = $stPaid->fetch() ?: [];
    $paid_amount_pln = (float)($paidRow['paid_pln'] ?? 0.0);
    $last_payment_at = $paidRow['last_paid_at'] ?? null;

    $due = $items_total - $paid_amount_pln;

    echo json_encode([
        'ok'               => true,
        'items_total'      => $items_total,
        'paid_amount_pln'  => $paid_amount_pln,
        'due'              => $due,
        'last_payment_at'  => $last_payment_at,
        'transactions'     => $transactions,
        // zgodność wstecz:
        'rows'             => $transactions,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    logg('error', 'payments.tx_list', 'exception', ['msg' => $e->getMessage()]);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
