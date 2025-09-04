<?php
declare(strict_types=1);

/**
 * /admin/payments/assign_to_group.php
 * Przypisanie płatności do wskazanej grupy zamówienia.
 *
 * Wejście (POST / JSON):
 * - payment_id (int)    – wymagane
 * - group_id   (int)    – wymagane
 * - csrf       (string) – wymagane (w body) LUB nagłówek X-CSRF-Token
 *
 * Zwraca JSON:
 * { ok: true, data: { payment_id, group_id, order_id, aggregate } }
 */

header('Content-Type: application/json; charset=utf-8');

use Engine\Payment\PaymentEngine;

// --- Bootstrap (auth + db + log) — auth uruchamia sesję i seeduje CSRF ---
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';

// --- PaymentEngine (case-sensitive ścieżki) ---
$paymentEnginePathCandidates = [
    __DIR__ . '/../../engine/Payment/PaymentEngine.php',
    __DIR__ . '/../../../engine/Payment/PaymentEngine.php',
];
foreach ($paymentEnginePathCandidates as $pp) {
    if (is_file($pp)) { require_once $pp; break; }
}

$CHANNEL = 'payments.assign_to_group';
$EVENT   = 'assign_to_group';

// 1) Tylko POST
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// 2) JSON → $_POST (akceptujemy też charset w nagłówku)
$raw = file_get_contents('php://input');
$ct  = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if ($raw && strpos($ct, 'application/json') === 0) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $_POST = $decoded + $_POST;
    }
}

// 3) CSRF (z body albo z nagłówka X-CSRF-Token)
$csrfHeader = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrfBody   = (string)($_POST['csrf'] ?? '');
$csrf       = $csrfBody !== '' ? $csrfBody : $csrfHeader;

if (empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
    exit;
}

// 4) Wymagane pola
$paymentId = (int)($_POST['payment_id'] ?? 0);
$groupId   = (int)($_POST['group_id'] ?? 0);
if ($paymentId <= 0 || $groupId <= 0) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'validation_error',
        'message' => 'payment_id i group_id są wymagane'
    ]);
    exit;
}

// 5) Owner z sesji (auth.php gwarantuje usera)
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($ownerId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'no_owner']);
    exit;
}

try {
    /** @var PDO $pdo */
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Brak połączenia PDO');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 6) Pobierz płatność (weryfikacja ownera)
    $stmt = $pdo->prepare("
        SELECT p.id, p.owner_id, p.order_id, p.order_group_id, p.amount, p.status, p.method_id
        FROM payments p
        WHERE p.id = :pid AND p.owner_id = :owner
        LIMIT 1
    ");
    $stmt->execute([':pid' => $paymentId, ':owner' => $ownerId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'payment_not_found']);
        exit;
    }

    // 7) Pobierz grupę + kontekst zamówienia
    $stmt = $pdo->prepare("
        SELECT og.id, og.order_id, og.deleted_at, o.owner_id AS order_owner_id
        FROM order_groups og
        JOIN orders o ON o.id = og.order_id
        WHERE og.id = :gid
        LIMIT 1
    ");
    $stmt->execute([':gid' => $groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'group_not_found']);
        exit;
    }
    if ((int)$group['order_owner_id'] !== $ownerId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden_group_owner']);
        exit;
    }
    if (!empty($group['deleted_at'])) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'group_deleted']);
        exit;
    }

    // 8) Spójność order_id
    $paymentOrderId = (int)$payment['order_id'];
    $groupOrderId   = (int)$group['order_id'];
    if ($paymentOrderId && $paymentOrderId !== $groupOrderId) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error' => 'order_mismatch',
            'message' => 'Płatność należy do innego zamówienia niż wskazana grupa.'
        ]);
        exit;
    }

    // 9) Transakcja DB
    $pdo->beginTransaction();

    // 9.1) Ustaw order_id jeśli brak
    if (!$paymentOrderId) {
        $paymentOrderId = $groupOrderId;
        $stmt = $pdo->prepare("
            UPDATE payments
            SET order_id = :oid
            WHERE id = :pid AND owner_id = :owner
            LIMIT 1
        ");
        $stmt->execute([
            ':oid'   => $paymentOrderId,
            ':pid'   => $paymentId,
            ':owner' => $ownerId
        ]);
    }

    // 9.2) Przypisz do grupy
    $stmt = $pdo->prepare("
        UPDATE payments
        SET order_group_id = :gid
        WHERE id = :pid AND owner_id = :owner
        LIMIT 1
    ");
    $stmt->execute([
        ':gid'   => $groupId,
        ':pid'   => $paymentId,
        ':owner' => $ownerId
    ]);

    // 9.3) Rekalkulacja
    if (!class_exists('\\Engine\\Payment\\PaymentEngine')) {
        throw new RuntimeException('Brak klasy PaymentEngine');
    }
    /** @var PaymentEngine $pe */
    $pe = method_exists('\\Engine\\Payment\\PaymentEngine', 'boot')
        ? PaymentEngine::boot($pdo, $ownerId)
        : new PaymentEngine($pdo, $ownerId);

    if (method_exists($pe, 'recalcGroupPaidStatus')) {
        $agg = $pe->recalcGroupPaidStatus($groupId);
    } elseif (method_exists($pe, 'recalcOrderPaidAggregates')) {
        $agg = $pe->recalcOrderPaidAggregates((int)$paymentOrderId);
    } else {
        $agg = ['note' => 'no_recalc_method'];
    }

    $pdo->commit();

    // 10) Log + odpowiedź
    logg('info', $CHANNEL, 'assigned_payment_to_group', [
        'event'      => $EVENT,
        'owner_id'   => $ownerId,
        'payment_id' => $paymentId,
        'group_id'   => $groupId,
        'order_id'   => $paymentOrderId,
        'aggregate'  => $agg,
        'context'    => 'admin_payment_ops'
    ]);

    echo json_encode([
        'ok'   => true,
        'data' => [
            'payment_id' => $paymentId,
            'group_id'   => $groupId,
            'order_id'   => $paymentOrderId,
            'aggregate'  => $agg,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_exception($e, $CHANNEL, 'assign_to_group_failed', [
        'event'      => $EVENT . '_error',
        'owner_id'   => $ownerId ?? null,
        'payment_id' => $paymentId ?? null,
        'group_id'   => $groupId ?? null,
        'post_keys'  => array_keys($_POST ?? []),
        'context'    => 'admin_payment_ops'
    ]);
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'error'   => 'exception',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
