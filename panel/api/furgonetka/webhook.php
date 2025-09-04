<?php
require_once __DIR__ . '/../../config/config.php';
$pdo = getPDO();

// Autoryzacja tokenem GET
$token = trim($_GET['token'] ?? '');
if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Brak tokenu']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM api_credentials WHERE provider = 'furgonetka' AND token = ?");
$stmt->execute([$token]);
$integration = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$integration) {
    http_response_code(403);
    echo json_encode(['error' => 'Nieprawidłowy token']);
    exit;
}
$ownerId = $integration['owner_id'];

// Dane wejściowe
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}
if (empty($input)) {
    $input = $_GET;
}

$trackingNumber = $input['tracking_number'] ?? null;
$trackingUrl    = $input['tracking_url'] ?? null;
$newStatus      = $input['status'] ?? null;

if (!$trackingNumber) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak tracking_number']);
    exit;
}

// Szukamy przesyłki w shipping_labels
$stmt = $pdo->prepare("
    SELECT sl.id, sl.order_id
    FROM shipping_labels sl
    JOIN orders o ON o.id = sl.order_id
    WHERE sl.tracking_number = ? AND o.owner_id = ?
");
$stmt->execute([$trackingNumber, $ownerId]);
$shipment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shipment) {
    http_response_code(404);
    echo json_encode(['error' => 'Przesyłka nieznaleziona']);
    exit;
}

// Aktualizacja shipping_labels
$stmt = $pdo->prepare("
    UPDATE shipping_labels 
    SET tracking_url = COALESCE(?, tracking_url),
        status = COALESCE(?, status),
        updated_at = NOW()
    WHERE id = ?
");
$stmt->execute([$trackingUrl, $newStatus, $shipment['id']]);

// Log do order_status_log
$note = '📦 Webhook Furgonetka: ' . $trackingNumber;
if ($newStatus) {
    $note .= ' (status: ' . $newStatus . ')';
}
$stmt = $pdo->prepare("
    INSERT INTO order_status_log (order_id, status, note, created_at)
    VALUES (?, ?, ?, NOW())
");
$stmt->execute([
    $shipment['order_id'],
    $newStatus ?? 'aktualizacja',
    $note
]);

echo json_encode(['success' => true]);
