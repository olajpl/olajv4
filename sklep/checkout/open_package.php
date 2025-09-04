<?php

declare(strict_types=1);
ob_start();
session_start();

require_once __DIR__ . '/../includes/checkout_loader.php'; // ← ładuje db + helpers + $checkout

// jeżeli potrzebujesz helperów jawnie (typy/stałe) – są już załadowane przez loader


// ———————————————————————————————————————————————————————————
// Helpers
// ———————————————————————————————————————————————————————————
// ——— helpers ———
// ——— helpers ———
function tableExists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = ?
    ");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function normalizeStatusForLog(string $status): string
{
    // obetnij sufiks po ":" żeby pasowało do ENUM w order_status_log.status
    $base = explode(':', $status, 2)[0];

    // opcjonalne mapowania nazw, jeśli kiedyś zmienisz nazewnictwo
    $map = [
        'gotowe_do_wysylki' => 'do_wyslania',
    ];
    return $map[$base] ?? $base;
}

function safeInsertStatusLog(PDO $pdo, int $orderId, string $old, string $new, array $opts = []): void
{
    static $canLog = null;
    if ($canLog === null) $canLog = tableExists($pdo, 'order_status_log');
    if (!$canLog) return;

    try {
        $logStatus = normalizeStatusForLog($new);

        $sql = "
            INSERT INTO order_status_log (order_id, status, changed_by_user_id, comment, created_at)
            VALUES (:oid, :status, :uid, :comment, NOW())
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':oid'     => $orderId,
            ':status'  => $logStatus,                    // enum('nowe','otwarta_paczka',...)
            ':uid'     => $opts['changed_by'] ?? null,   // nullable
            ':comment' => $opts['note'] ?? 'open_package',
        ]);
    } catch (\Throwable $e) {
        @file_put_contents(
            __DIR__ . '/../logs/open_package_log_error.log',
            date('c') . ' | ' . $e->getMessage() . PHP_EOL,
            FILE_APPEND
        );
    }
}

function setOrderStatus(PDO $pdo, int $orderId, string $newStatus, array $opts = []): bool
{
    $terminal = ['do_wyslania', 'wyslane', 'zrealizowane', 'zarchiwizowane', 'anulowane'];

    $pdo->beginTransaction();
    try {
        $cur = $pdo->prepare("SELECT order_status FROM orders WHERE id = ? FOR UPDATE");
        $cur->execute([$orderId]);
        $old = $cur->fetchColumn();
        if ($old === false) {
            $pdo->rollBack();
            return false;
        }

        if (in_array((string)$old, $terminal, true)) {
            $pdo->commit();
            return true;
        }

        if ($old !== $newStatus) {
            $upd = $pdo->prepare("UPDATE orders SET order_status = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$newStatus, $orderId]);

            // log do order_status_log (best-effort)
            safeInsertStatusLog($pdo, $orderId, (string)$old, $newStatus, $opts);
        }

        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @file_put_contents(
            __DIR__ . '/../logs/open_package_error.log',
            date('c') . ' | setOrderStatus | ' . $e->getMessage() . PHP_EOL,
            FILE_APPEND
        );
        return false;
    }
}

// ———————————————————————————————————————————————————————————
// Wejście
// ———————————————————————————————————————————————————————————
$inputToken = $_POST['token'] ?? $_GET['token'] ?? null;
if (!$inputToken || !is_string($inputToken)) {
    http_response_code(400);
    exit('Brak tokenu.');
}

// Pobierz paczkę i zamówienie po checkout_token
$stmt = $pdo->prepare("
    SELECT og.id AS order_group_id, og.checkout_token, o.id AS order_id, o.order_status
    FROM order_groups og
    JOIN orders o ON o.id = og.order_id
    WHERE og.checkout_token = :token
    LIMIT 1
");
$stmt->execute([':token' => $inputToken]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    exit('Nie znaleziono paczki dla podanego tokenu.');
}

$orderId = (int)$row['order_id'];
$token   = (string)$row['checkout_token'];

// ———————————————————————————————————————————————————————————
// Zmień status na „otwarta_paczka:add_products”
// ———————————————————————————————————————————————————————————
$ok = setOrderStatus($pdo, $orderId, 'otwarta_paczka:add_products', [
    'source' => 'checkout',
    'note'   => 'open_package.php',
]);

// ✅ Powrót do thank_you — BEZ locked=1 (locked tylko dla stanów twardych)
if ($ok) {
    header('Location: /checkout/thank_you.php?token=' . urlencode($token) . '&msg=package_opened');
    exit;
}

// ❌ Fallback
http_response_code(500);
exit('Nie udało się zmienić statusu zamówienia na „otwarta_paczka:add_products”.');
