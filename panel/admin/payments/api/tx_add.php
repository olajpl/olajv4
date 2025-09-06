<?php
// admin/payments/api/tx_add.php — V4 engine-only, schema-aligned
declare(strict_types=1);

require_once __DIR__ . '/../../../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (\session_status() !== \PHP_SESSION_ACTIVE) \session_start();

try {
    $ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
    $userId  = (int)($_SESSION['user']['id'] ?? 0);

    $csrfPost = (string)($_POST['csrf_token'] ?? ($_POST['csrf'] ?? ''));
    $csrfOk   = isset($_SESSION['csrf_token']) && \hash_equals($_SESSION['csrf_token'], $csrfPost);
    if (!$csrfOk) { http_response_code(403); throw new \RuntimeException('CSRF mismatch'); }

    $orderId    = (int)($_POST['order_id'] ?? 0);
    $groupId    = (int)($_POST['order_group_id'] ?? 0);
    $amount     = (float)($_POST['amount'] ?? 0);
    $currency   = strtoupper(substr((string)($_POST['currency'] ?? 'PLN'), 0, 3));
    $methodTxt  = trim((string)($_POST['method'] ?? ''));    // tekst do kolumny payments.method
    $statusTxt  = trim((string)($_POST['status'] ?? 'zaksięgowana'));
    $typeTxt    = trim((string)($_POST['transaction_type'] ?? 'wpłata'));
    $provider   = trim((string)($_POST['provider'] ?? 'manual'));
    $providerTx = substr(trim((string)($_POST['provider_payment_id'] ?? ($_POST['provider_tx_id'] ?? ''))), 0, 64);
    $bookedAt   = trim((string)($_POST['booked_at'] ?? '')); // mapujemy na paid_at

    if ($ownerId <= 0 || $orderId <= 0) { http_response_code(422); throw new \InvalidArgumentException('Missing owner_id/order_id'); }
    if ($typeTxt !== 'wpłata')           { http_response_code(422); throw new \InvalidArgumentException('Obsługujemy teraz tylko typ: wpłata.'); }
    if ($statusTxt !== 'zaksięgowana')   { http_response_code(422); throw new \InvalidArgumentException('Obsługujemy teraz tylko status: zaksięgowana.'); }
    if ($amount <= 0)                    { http_response_code(422); throw new \InvalidArgumentException('Kwota musi być > 0 dla wpłaty.'); }

    if (!($pdo instanceof \PDO)) throw new \RuntimeException('PDO not available');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

    // Order must belong to owner
    $st = $pdo->prepare("SELECT id FROM orders WHERE id=:oid AND owner_id=:own LIMIT 1");
    $st->execute([':oid' => $orderId, ':own' => $ownerId]);
    if (!$st->fetchColumn()) { http_response_code(404); throw new \RuntimeException('Order not found'); }

    // Group ownership (optional)
    if ($groupId > 0) {
        $st = $pdo->prepare("
            SELECT og.id
              FROM order_groups og
              JOIN orders o ON o.id = og.order_id AND o.owner_id = :own
             WHERE og.id = :gid AND og.order_id = :oid
             LIMIT 1
        ");
        $st->execute([':own' => $ownerId, ':gid' => $groupId, ':oid' => $orderId]);
        if (!$st->fetchColumn()) { http_response_code(422); throw new \InvalidArgumentException('Invalid order_group_id for this order/owner'); }
    }

    // Wybór payment_method_id (uwaga na anty-HY093: :t1 i :t2)
    $methodId = 0;
    if ($methodTxt !== '') {
        $st = $pdo->prepare("
            SELECT id FROM payment_methods
             WHERE owner_id = :oid
               AND (LOWER(name) = LOWER(:t1) OR LOWER(code) = LOWER(:t2))
             ORDER BY sort_order ASC, id ASC
             LIMIT 1
        ");
        $st->execute([':oid' => $ownerId, ':t1' => $methodTxt, ':t2' => $methodTxt]);
        $methodId = (int)($st->fetchColumn() ?: 0);
    }
    if (!$methodId) {
        $st = $pdo->prepare("SELECT id FROM payment_methods WHERE owner_id=:oid ORDER BY sort_order ASC, id ASC LIMIT 1");
        $st->execute([':oid' => $ownerId]);
        $methodId = (int)($st->fetchColumn() ?: 0);
    }
    if (!$methodId) { http_response_code(422); throw new \RuntimeException('Brak skonfigurowanych metod płatności.'); }

    if (!class_exists('\Engine\Orders\PaymentEngine')) throw new \RuntimeException('Engine\\Orders\\PaymentEngine not found');
    $eng = new \Engine\Orders\PaymentEngine($pdo, $ownerId);

    // 1) Draft → Paid (wewnątrz silnika transakcje)
    $note  = "UI modal; {$provider}" . ($providerTx ? " / {$providerTx}" : '');
    $payId = $eng->addManualPayment($orderId, ($groupId ?: null), $methodId, $amount, $currency, $note, $userId);

    // 2) Uzupełnij provider/provider_payment_id/paid_at/method
    $eng->setProviderData(
        paymentId: $payId,
        ownerId:   $ownerId,
        provider:  ($provider !== '' ? $provider : 'manual'),
        providerPaymentId: ($providerTx !== '' ? $providerTx : null),
        bookedAt:  ($bookedAt !== '' ? $bookedAt : null),
        methodText: ($methodTxt !== '' ? $methodTxt : null)
    );

    logg('info', 'payments', 'tx_add.ok', [
        'owner_id'   => $ownerId,
        'order_id'   => $orderId,
        'group_id'   => $groupId,
        'payment_id' => $payId,
        'amount'     => $amount,
        'currency'   => $currency,
        'payment_method_id' => $methodId,
        'provider'   => $provider,
        'provider_payment_id'=> $providerTx,
        'paid_at'    => $bookedAt ?: null,
    ]);

    http_response_code(201);
    echo json_encode(['ok' => true, 'payment_id' => (int)$payId], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    log_exception($e, ['api' => 'tx_add', 'post' => $_POST ?? []]);
    if (!http_response_code() || http_response_code() === 200) { http_response_code(400); }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
