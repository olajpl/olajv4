<?php
declare(strict_types=1);
require_once __DIR__.'/../../../../bootstrap.php';

use Engine\Orders\OrderEngine;

if (session_status() === PHP_SESSION_NONE) session_start();

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);

$csrf     = $_POST['csrf'] ?? '';
$orderId  = (int)($_POST['order_id'] ?? 0);
$groupId  = (int)($_POST['order_group_id'] ?? ($_POST['group_id'] ?? 0));
$itemId   = (int)($_POST['item_id'] ?? 0);

// tryb odpowiedzi: 'json' (default) lub 'html' (redirect)
$returnMode = 'json';
if (
    isset($_POST['return']) && $_POST['return'] === 'html'
    || (!empty($_POST['return_to']))
    || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false
        && (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'))
) {
    $returnMode = 'html';
}

$bad = (!$ownerId || !$orderId || !$groupId || !$itemId);
$forbidden = !hash_equals($_SESSION['csrf_token'] ?? '', $csrf);

if ($bad || $forbidden) {
    if ($returnMode === 'html') {
        $_SESSION['flash_error'] = $bad ? 'Bad request' : 'Forbidden';
        $back = $_POST['return_to'] ?? ("/admin/orders/view.php?id={$orderId}&group_id={$groupId}");
        header('Location: ' . $back);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($bad ? 400 : 403);
    echo json_encode(['ok'=>false,'error'=>$bad ? 'Bad request':'Forbidden']);
    exit;
}

$patch = [];
foreach (['qty','unit_price','vat_rate'] as $k) {
    if (isset($_POST[$k]) && $_POST[$k] !== '') $patch[$k] = (float)$_POST[$k];
}

try {
    $engine = new OrderEngine($pdo);
    $res = $engine->updateOrderItem($ownerId, $orderId, $groupId, $itemId, $patch);

    if ($returnMode === 'html') {
        if (!empty($res['ok'])) {
            $_SESSION['flash_ok'] = 'Pozycja zaktualizowana.';
            // opcjonalnie anchor do itemu:
            $back = $_POST['return_to'] ?? ("/admin/orders/view.php?id={$orderId}&group_id={$groupId}#item-{$itemId}");
        } else {
            $_SESSION['flash_error'] = 'Nie udało się zaktualizować pozycji: ' . (($res['sql_msg'] ?? $res['message'] ?? 'unknown'));
            $back = $_POST['return_to'] ?? ("/admin/orders/view.php?id={$orderId}&group_id={$groupId}");
        }
        header('Location: ' . $back);
        exit;
    }

    // JSON (AJAX)
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($res);
} catch (Throwable $e) {
    if ($returnMode === 'html') {
        $_SESSION['flash_error'] = 'Błąd: ' . $e->getMessage();
        $back = $_POST['return_to'] ?? ("/admin/orders/view.php?id={$orderId}&group_id={$groupId}");
        header('Location: ' . $back);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
