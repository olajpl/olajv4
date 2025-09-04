<?php
// admin/payments/api/tx_list.php
declare(strict_types=1);

use PDO;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';

header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

try {
    $ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
    $orderId = (int)($_GET['order_id'] ?? 0);
    if ($ownerId <= 0 || $orderId <= 0) {
        throw new InvalidArgumentException('Missing owner_id / order_id');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // 1) Suma pozycji z order_items (brutto/price – w Olaj V4 przyjmujemy unit_price * qty)
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(oi.unit_price * oi.qty),0) AS items_total
        FROM order_groups og
        JOIN orders o ON o.id = og.order_id AND o.owner_id = :oid
        JOIN order_items oi ON oi.order_group_id = og.id
        WHERE og.order_id = :order_id
    ");
    $st->execute([':oid' => $ownerId, ':order_id' => $orderId]);
    $itemsTotal = (float)($st->fetchColumn() ?: 0);

    // 2) Lista płatności (payments) + agregaty (tylko PLN – jeśli masz multi-currency, zrób konwersję)
    $st = $pdo->prepare("
        SELECT p.id, p.order_group_id, p.amount, p.currency, p.status, p.method_id,
               pm.name AS method_name, p.provider, p.provider_tx_id,
               COALESCE(p.booked_at, p.created_at) AS booked_at, p.transaction_type
        FROM payments p
        LEFT JOIN payment_methods pm ON pm.id = p.method_id
        JOIN orders o ON o.id = p.order_id AND o.owner_id = :oid
        WHERE p.order_id = :order_id
        ORDER BY COALESCE(p.booked_at, p.created_at) DESC, p.id DESC
    ");
    $st->execute([':oid' => $ownerId, ':order_id' => $orderId]);
    $rows = $st->fetchAll() ?: [];

    $paid = 0.0;
    $lastPaidAt = null;
    $tx = [];
    foreach ($rows as $r) {
        $amt = (float)$r['amount'];
        $type = (string)($r['transaction_type'] ?? 'wpłata'); // jeśli masz pole; inaczej heurystyka po znaku kwoty
        if ($type === '') $type = ($amt < 0 ? 'zwrot' : 'wpłata');

        if ((string)$r['status'] === 'paid') {
            $paid += $amt;
            if (!$lastPaidAt || $r['booked_at'] > $lastPaidAt) {
                $lastPaidAt = (string)$r['booked_at'];
            }
        }

        $tx[] = [
            'id'              => (int)$r['id'],
            'order_group_id'  => (int)$r['order_group_id'],
            'transaction_type' => $type,
            'status'          => (string)$r['status'],
            'amount'          => $amt,
            'amount_pln'      => (string)$r['currency'] === 'PLN' ? $amt : $amt, // TODO FX jeśli trzeba
            'method'          => $r['method_name'] ?? null,
            'provider'        => $r['provider'] ?? null,
            'provider_tx_id'  => $r['provider_tx_id'] ?? null,
            'booked_at'       => $r['booked_at'] ?? null,
        ];
    }
    $due = $itemsTotal - $paid;

    echo json_encode([
        'ok'             => true,
        'items_total'    => $itemsTotal,
        'paid_amount_pln' => $paid,
        'due'            => $due,
        'last_payment_at' => $lastPaidAt,
        'transactions'   => $tx,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    log_exception($e, ['api' => 'tx_list', 'get' => $_GET ?? []]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
