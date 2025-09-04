<?php
// /admin/payments/api/recalc_group.php — Rekalkulacja paid_status grupy
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';
require_once __DIR__ . '/../../../engine/Payment/PaymentEngine.php';

use Engine\Payment\PaymentEngine;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed']); exit;
    }
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf_invalid']); exit;
    }
    $owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
    $group_id = (int)($_POST['order_group_id'] ?? 0);
    if ($group_id <= 0) { echo json_encode(['ok' => false, 'error' => 'missing_group']); exit; }

    $stmt = $pdo->prepare("SELECT og.id FROM order_groups og JOIN orders o ON o.id=og.order_id WHERE og.id=? AND o.owner_id=?");
    $stmt->execute([$group_id, $owner_id]);
    if (!$stmt->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'group_not_found']); exit; }

    $pe = new PaymentEngine($pdo, $owner_id);
    $agg = $pe->recalcGroupPaidStatus($group_id);

    echo json_encode(['ok' => true, 'aggregate' => $agg]);
} catch (Throwable $e) {
    logg('error', 'payments.recalc_group', 'exception', ['msg' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()]);
}
