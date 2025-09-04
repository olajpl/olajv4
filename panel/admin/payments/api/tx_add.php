<?php
// admin/payments/api/tx_add.php
declare(strict_types=1);

use PDO;
use Engine\Orders\PaymentEngine;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php';
require_once __DIR__ . '/../../../engine/autoload.php';

header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

try {
    $ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
    $userId  = (int)($_SESSION['user']['id'] ?? 0);
    $csrfOk  = isset($_POST['csrf'], $_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf']);

    if (!$csrfOk) throw new RuntimeException('CSRF mismatch');

    $orderId   = (int)($_POST['order_id'] ?? 0);
    $groupId   = (int)($_POST['order_group_id'] ?? 0); // z modala
    $amount    = (float)($_POST['amount'] ?? 0);
    $currency  = (string)($_POST['currency'] ?? 'PLN');
    $methodTxt = trim((string)($_POST['method'] ?? ''));
    $statusTxt = trim((string)($_POST['status'] ?? 'zaksięgowana')); // 'zaksięgowana'|'oczekująca'|'odrzucona'|'anulowana'
    $typeTxt   = trim((string)($_POST['transaction_type'] ?? 'wpłata')); // 'wpłata'|'zwrot'|'korekta'
    $provider  = trim((string)($_POST['provider'] ?? 'manual'));
    $providerTx = trim((string)($_POST['provider_tx_id'] ?? ''));
    $bookedAt  = (string)($_POST['booked_at'] ?? '');

    if ($ownerId <= 0 || $orderId <= 0) throw new InvalidArgumentException('Missing owner_id/order_id');

    // Na dziś wspieramy tylko „wpłatę” i status „zaksięgowana” → stanie się 'paid'
    if ($typeTxt !== 'wpłata') {
        throw new InvalidArgumentException('Obsługujemy teraz tylko typ: wpłata (zwrot/korekta później).');
    }
    if ($statusTxt !== 'zaksięgowana') {
        throw new InvalidArgumentException('Obsługujemy teraz tylko status: zaksięgowana.');
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('Kwota musi być > 0 dla wpłaty.');
    }

    // Ustal payment_method_id na podstawie tekstu (lub fallback)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $methodId = null;
    if ($methodTxt !== '') {
        $st = $pdo->prepare("
            SELECT id FROM payment_methods
            WHERE owner_id = :oid
              AND (
                    LOWER(name) = LOWER(:txt)
                 OR LOWER(code) = LOWER(:txt)
                 OR LOWER(short_name) = LOWER(:txt)
              )
            LIMIT 1
        ");
        $st->execute([':oid' => $ownerId, ':txt' => $methodTxt]);
        $methodId = (int)($st->fetchColumn() ?: 0);
    }
    if (!$methodId) {
        // sensowny fallback – np. 'Przelew' jeśli istnieje, inaczej pierwszy aktywny
        $st = $pdo->prepare("SELECT id FROM payment_methods WHERE owner_id=:oid ORDER BY sort_order ASC, id ASC LIMIT 1");
        $st->execute([':oid' => $ownerId]);
        $methodId = (int)($st->fetchColumn() ?: 0);
    }
    if (!$methodId) throw new RuntimeException('Brak skonfigurowanych metod płatności.');

    // Zapis przez silnik → to wyemituje CW `payment.recorded` (Messenger)
    $eng   = new PaymentEngine($pdo, $ownerId);
    $payId = $eng->addManualPayment($orderId, $groupId, $methodId, $amount, $currency, "UI modal; $provider / $providerTx", $userId);

    // Aktualizacja pól pomocniczych (provider, tx_id, booked_at), jeśli są w tabeli
    try {
        $sql = "UPDATE payments SET provider=:p, provider_tx_id=:tx" . ($bookedAt ? ", booked_at=:ba" : "") . " WHERE id=:id AND order_id=:oid";
        $st2 = $pdo->prepare($sql);
        $params = [
            ':p' => $provider ?: 'manual',
            ':tx' => $providerTx ?: null,
            ':id' => $payId,
            ':oid' => $orderId,
        ];
        if ($bookedAt) $params[':ba'] = $bookedAt;
        $st2->execute($params);
    } catch (Throwable $e) {
        // nie blokujemy – to są pola dodatkowe
        log_exception($e, ['where' => 'tx_add.update_optional', 'payment_id' => $payId]);
    }

    logg('info', 'payments', 'tx_add.ok', [
        'order_id' => $orderId,
        'group_id' => $groupId,
        'payment_id' => $payId,
        'amount' => $amount,
        'currency' => $currency,
        'method_id' => $methodId
    ]);

    echo json_encode(['ok' => true, 'payment_id' => $payId], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    log_exception($e, ['api' => 'tx_add', 'post' => $_POST ?? []]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
