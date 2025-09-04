<?php
// admin/shipping/furgonetka_refresh.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/shipping_furgonetka.php';
require_once __DIR__ . '/../../api/furgonetka/furgonetka_api.php';
@include_once __DIR__ . '/../../includes/log.php';

if (!function_exists('flog')) {
    function flog(string $m, string $lvl = 'info', array $ctx = []): void
    {
        $meta = ['event' => 'furgonetka', 'source' => 'refresh'];
        if (function_exists('logg')) {
            logg($lvl, 'shipping.furgonetka', $m, $ctx, $meta);
            return;
        }
        error_log('[furgo.refresh][' . $lvl . '][' . $m . '] ' . json_encode($ctx + $meta, JSON_UNESCAPED_UNICODE));
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$orderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) && !empty($_SESSION['csrf'])) $_SESSION['csrf_token'] = $_SESSION['csrf'];
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$csrf)) {
        http_response_code(403);
        exit('CSRF');
    }
}
if ($ownerId <= 0 || $orderId <= 0) {
    http_response_code(400);
    exit('bad params');
}

$st = $pdo->prepare("SELECT * FROM shipping_labels WHERE order_id=? AND provider='furgonetka' ORDER BY id DESC LIMIT 1");
$st->execute([$orderId]);
$label = $st->fetch(PDO::FETCH_ASSOC);
if (!$label) {
    $_SESSION['error_message'] = 'Brak etykiety do odświeżenia';
    header("Location: ../orders/view.php?id=" . $orderId);
    exit;
}

$externalId = $label['external_id'] ?? null;
$labelId    = (int)$label['id'];

try {
    $api = new FurgonetkaAPI(['pdo' => $pdo, 'owner_id' => $ownerId]);

    // Prosty GET szczegółów przesyłki:
    // Często endpoint to /v2/shipments/{id} – jeśli u Was inny, zmień tutaj.
    $ref = new ReflectionClass($api);
    $prop = $ref->getProperty('apiBase');
    $prop->setAccessible(true);
    $base = rtrim((string)$prop->getValue($api), '/') . '/';

    $token = getFurgonetkaToken($pdo, $ownerId);
    $url = $base . 'shipments/' . urlencode((string)$externalId);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: OlajV4/1.0 (+panel.olaj.pl)',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $code >= 400) {
        throw new RuntimeException("Błąd odczytu przesyłki ($code): " . ($err ?: $raw ?: 'brak treści'));
    }
    $j = json_decode((string)$raw, true) ?: [];

    $tracking = $j['tracking_number'] ?? ($j['tracking']['number'] ?? null);
    $price    = isset($j['price']) ? (float)$j['price'] : null;
    $hasLabel = isset($j['label_url']) || isset($j['label']['url']) || isset($j['label']);

    $status = $hasLabel ? 'label_ready' : 'ordered';

    $up = $pdo->prepare("
    UPDATE shipping_labels
    SET status=:st, tracking_number=:tn, price=:pr, updated_at=NOW()
    WHERE id=:id
  ");
    $up->execute(['st' => $status, 'tn' => $tracking, 'pr' => $price, 'id' => $labelId]);

    $_SESSION['success_message'] = '🔄 Status etykiety odświeżony.';
} catch (Throwable $e) {
    $_SESSION['error_message'] = '❌ Odświeżenie nieudane: ' . $e->getMessage();
}
header("Location: ../orders/view.php?id=" . $orderId);
exit;
