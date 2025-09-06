<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../bootstrap.php';

use Engine\Orders\OrderEngine;

if (session_status() === PHP_SESSION_NONE) session_start();

/* ───────── Tryb odpowiedzi: JSON (default) lub HTML (redirect) ───────── */
$returnMode = 'json';
if (
    (isset($_POST['return']) && $_POST['return'] === 'html')
    || (!empty($_POST['return_to']))
    || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false
        && (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'))
) {
    $returnMode = 'html';
}
if ($returnMode === 'json') {
    header('Content-Type: application/json; charset=utf-8');
}

/* ───────── Autoryzacja + CSRF ───────── */
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$csrf    = $_POST['csrf'] ?? '';
if (!$ownerId) {
    $msg = 'Unauthorized';
    if ($returnMode === 'html') { $_SESSION['flash_error'] = $msg; header('Location: /admin/'); exit; }
    http_response_code(401); echo json_encode(['ok'=>false,'error'=>$msg]); exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    $msg = 'Forbidden';
    if ($returnMode === 'html') { $_SESSION['flash_error'] = $msg; header('Location: /admin/'); exit; }
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>$msg]); exit;
}

/* ───────── Wejście ─────────
   Minimalny zestaw: name, qty, unit_price, vat_rate, oraz client_id LUB (order_id + group_id)
*/
$orderId   = (int)($_POST['order_id'] ?? 0);
$groupId   = (int)($_POST['order_group_id'] ?? ($_POST['group_id'] ?? 0));
$clientId  = (int)($_POST['client_id'] ?? 0);
$productId = isset($_POST['product_id']) && $_POST['product_id'] !== '' ? (int)$_POST['product_id'] : null;

$name       = trim((string)($_POST['name'] ?? ''));
$qty        = isset($_POST['qty']) ? (float)$_POST['qty'] : 1.0;
$unitPrice  = isset($_POST['unit_price']) ? (float)$_POST['unit_price'] : 0.0;
$vatRate    = isset($_POST['vat_rate']) ? (float)$_POST['vat_rate'] : 23.0;
$sku        = (string)($_POST['sku'] ?? '');
$sourceType = (string)($_POST['source_type'] ?? 'parser');   // enum: order_item_source
$channel    = (string)($_POST['channel'] ?? '');             // 'shop'|'messenger'|'live'|'admin'...

/* Jeżeli nie podano client_id, spróbuj pobrać go z orders */
if (!$clientId && $orderId > 0) {
    $st = $pdo->prepare("SELECT client_id FROM orders WHERE id = :oid AND owner_id = :own LIMIT 1");
    $st->execute([':oid'=>$orderId, ':own'=>$ownerId]);
    $clientId = (int)($st->fetchColumn() ?: 0);
}

/* Walidacja podstawowa */
if ($clientId <= 0 || $name === '' || $qty <= 0) {
    $msg = 'Invalid payload';
    if ($returnMode === 'html') {
        $_SESSION['flash_error'] = $msg;
        $back = $_POST['return_to'] ?? ($orderId ? "/admin/orders/view.php?id={$orderId}&group_id={$groupId}" : "/admin/orders/");
        header('Location: ' . $back); exit;
    }
    http_response_code(422); echo json_encode(['ok'=>false,'error'=>$msg]); exit;
}

/* ───────── Biznes: dodanie pozycji ───────── */
try {
    $engine = new OrderEngine($pdo);

    $payload = [
        'owner_id'    => $ownerId,
        'client_id'   => $clientId,
        'product_id'  => $productId,
        'name'        => $name,
        'qty'         => $qty,
        'unit_price'  => $unitPrice,
        'vat_rate'    => $vatRate,
        'sku'         => $sku,
        'source_type' => $sourceType,
        'channel'     => $channel,
    ];

    $res = $engine->addOrderItem($payload);

    if ($returnMode === 'html') {
        if (!empty($res['ok'])) {
            $_SESSION['flash_ok'] = 'Pozycja została dodana.';
            // użyj order_id/group_id z wyniku engine (mogą się różnić od wejściowych)
            $back = $_POST['return_to']
                ?? ("/admin/orders/view.php?id={$res['order_id']}&group_id={$res['order_group_id']}#item-{$res['order_item_id']}");
        } else {
            $err = $res['sql_msg'] ?? $res['message'] ?? $res['reason'] ?? 'unknown';
            $_SESSION['flash_error'] = 'Nie udało się dodać pozycji: ' . $err;
            $fallbackOrder = $orderId ?: ($res['order_id'] ?? 0);
            $fallbackGroup = $groupId ?: ($res['order_group_id'] ?? 0);
            $back = $_POST['return_to']
                ?? ($fallbackOrder ? "/admin/orders/view.php?id={$fallbackOrder}&group_id={$fallbackGroup}" : "/admin/orders/");
        }
        header('Location: ' . $back);
        exit;
    }

    // JSON
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($res, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($returnMode === 'html') {
        $_SESSION['flash_error'] = 'Błąd: ' . $e->getMessage();
        $back = $_POST['return_to'] ?? ($orderId ? "/admin/orders/view.php?id={$orderId}&group_id={$groupId}" : "/admin/orders/");
        header('Location: ' . $back);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
