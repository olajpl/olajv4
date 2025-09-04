<?php
// checkout/send_package.php — zamknięcie paczki (tryb: tylko płatność)
declare(strict_types=1);
ob_start();
session_start();

require_once __DIR__ . '/../includes/checkout_loader.php'; // ← ładuje db + helpers + $checkout

// jeżeli potrzebujesz helperów jawnie (typy/stałe) – są już załadowane przez loader
if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf_checkout'] ?? '')) {
    http_response_code(403);
    exit('CSRF');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

if (empty($checkout['order_id']) || empty($checkout['token'])) {
    http_response_code(400);
    exit('Brak kontekstu zamówienia.');
}

$orderId = (int)$checkout['order_id'];
$token   = (string)$checkout['token'];

/**
 * 1) Walidacje wejścia (checkbox + ewentualna captcha)
 */
if (empty($_POST['confirm_lock'])) {
    // brak potwierdzenia — wracamy bez "locked"
    header('Location: /checkout/thank_you.php?token=' . urlencode($token) . '&msg=confirm_required');
    exit;
}

// Captcha math — jeśli włączona u właściciela
try {
    $stmt = $pdo->prepare("SELECT captcha_lock_enabled FROM owners WHERE id = ? LIMIT 1");
    $stmt->execute([$checkout['owner_id']]);
    $captcha_enabled = (bool)$stmt->fetchColumn();

    if ($captcha_enabled) {
        require_once __DIR__ . '/../includes/captcha_math.php';
        if (!isset($_POST['captcha_answer']) || !validateCaptchaMath((string)$_POST['captcha_answer'])) {
            header('Location: /checkout/thank_you.php?token=' . urlencode($token) . '&msg=captcha_failed');
            exit;
        }
    }
} catch (\Throwable $e) {
    // ciche – captcha to best effort
}

/**
 * 2) Ustawienie statusu zamówienia na "otwarta_paczka:payment_only"
 *    (zamyka dodawanie produktów; zostaje już tylko płatność)
 */
if (!function_exists('setOrderStatus')) {
    function setOrderStatus(PDO $pdo, int $orderId, string $newStatus, array $opts = []): bool
    {
        $pdo->beginTransaction();
        try {
            $cur = $pdo->prepare("SELECT order_status FROM orders WHERE id = ? FOR UPDATE");
            $cur->execute([$orderId]);
            $old = $cur->fetchColumn();
            if ($old === false) {
                $pdo->rollBack();
                return false;
            }

            // dozwolone przejścia: z "nowe" albo "otwarta_paczka:add_products" do "otwarta_paczka:payment_only"
            $allowedFrom = ['nowe', 'otwarta_paczka:add_products', 'otwarta_paczka:payment_only'];
            if (!in_array((string)$old, $allowedFrom, true)) {
                // nic nie zmieniamy, ale nie robimy z tego błędu
                $pdo->commit();
                return true;
            }

            if ($old !== $newStatus) {
                $upd = $pdo->prepare("UPDATE orders SET order_status = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$newStatus, $orderId]);

                // historia (nice-to-have)
                try {
                    $h = $pdo->prepare("
                        INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, source, note, created_at)
                        VALUES (:oid,:old,:new,:by,:src,:note,NOW())
                    ");
                    $h->execute([
                        ':oid' => $orderId,
                        ':old' => $old,
                        ':new' => $newStatus,
                        ':by' => $opts['changed_by'] ?? null,
                        ':src' => $opts['source'] ?? 'checkout',
                        ':note' => $opts['note'] ?? 'send_package',
                    ]);
                } catch (\Throwable $e) { /* ignore */
                }
            }

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            @file_put_contents(__DIR__ . '/../logs/send_package_error.log', date('c') . ' | ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            return false;
        }
    }
}

$ok = setOrderStatus($pdo, $orderId, 'oczekuje_na_płatność', [
    'source' => 'checkout',
    'note'   => 'send_package.php',
]);

/**
 * 3) Redirect z powrotem na thank_you (bez &locked=1).
 *    Jeśli chcesz pokazać komunikat, dorzuciłem msg=closed_to_payment.
 */
if ($ok) {
    header('Location: /checkout/thank_you.php?token=' . urlencode($token) . '&msg=closed_to_payment');
    exit;
}

// fallback w razie problemu
header('Location: /checkout/thank_you.php?token=' . urlencode($token) . '&msg=failed_to_close');
exit;
