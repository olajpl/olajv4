<?php
// admin/live/ajax/ajax_finalize_batch.php
declare(strict_types=1);

require_once __DIR__ . '/__live_boot.php'; // APP_ROOT + session + $pdo (+ opcjonalnie auth włącz wg uwagi niżej)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function out(array $arr, int $code = 200): void {
    if (!headers_sent()) http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 1) Kontekst z sesji
$owner_id    = (int)($_SESSION['user']['owner_id'] ?? 0);
$operator_id = (int)($_SESSION['user']['id'] ?? 0);

// 2) live_id z POST/GET/JSON
$live_id = isset($_POST['live_id']) ? (int)$_POST['live_id'] : 0;
if (!$live_id && isset($_GET['live_id'])) $live_id = (int)$_GET['live_id'];
if (!$live_id) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) $live_id = (int)($json['live_id'] ?? 0);
    }
}

// 3) Walidacja kontekstu
if ($owner_id <= 0 || $operator_id <= 0 || $live_id <= 0) {
    out(['success' => false, 'error' => 'Brak kontekstu (owner_id/live_id/operator_id).'], 400);
}

// 4) LIVE należy do ownera?
$st = $pdo->prepare("SELECT id FROM live_streams WHERE id=? AND owner_id=? LIMIT 1");
$st->execute([$live_id, $owner_id]);
if (!$st->fetch()) {
    out(['success' => false, 'error' => 'LIVE nie należy do tego właściciela.'], 403);
}

// 5) Engine’y
require_once APP_ROOT . '/engine/orders/ClientEngine.php';
require_once APP_ROOT . '/engine/orders/ProductEngine.php';
require_once APP_ROOT . '/engine/orders/PaymentEngine.php';
require_once APP_ROOT . '/engine/live/LiveEngine.php';

try {
    $clients  = new \Engine\Orders\ClientEngine($pdo);
    $products = new \Engine\Orders\ProductEngine($pdo);
    $payments = new \Engine\Orders\PaymentEngine($pdo);
    $live     = new \Engine\Live\LiveEngine($pdo, $clients, $products, $payments);

    // KLUCZ: dwa argumenty (int, int)
    $res = $live->finalizeBatch($live_id, $operator_id);

    out([
        'success'   => true,
        'message'   => 'Zafinalizowano batch.',
        'finalized' => $res['finalized'] ?? ($res['moved'] ?? 0),
        'orders'    => $res['orders'] ?? [],
    ]);
} catch (\Throwable $e) {
    out(['success' => false, 'error' => $e->getMessage()], 500);
}
