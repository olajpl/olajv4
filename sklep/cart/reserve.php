<?php
// cart/reserve.php – Rezerwacja produktu przez klienta (V4, shop-friendly)
// 1) Bootstrap + bezpieczne nagłówki
// 2) CSRF (konta/sklep/header) – łagodny tryb by działało z publicznego index.php
// 3) Walidacja wejścia + lookup klienta (scoped by owner) + produkt active
// 4) Rate-limit 30s na tę samą rezerwację
// 5) UPSERT do product_reservations (reaktywacja z 'anulowany' -> 'oczekuje')
// 6) Spójna odpowiedź JSON: status/message

declare(strict_types=1);
ob_start();
session_start();

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

// [1] Tylko POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// [2] CSRF – akceptujemy kilka wariantów (panel/sklep/header)
$csrfPost   = (string)($_POST['csrf'] ?? '');
$csrfHeader = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrfOK = false;

if (!empty($_SESSION['csrf_konto']) && hash_equals($_SESSION['csrf_konto'], $csrfPost)) {
    $csrfOK = true;
} elseif (!empty($_SESSION['csrf']) && (hash_equals($_SESSION['csrf'], $csrfPost) || hash_equals($_SESSION['csrf'], $csrfHeader))) {
    $csrfOK = true;
}

// Jeśli chcesz twardo egzekwować CSRF, odkomentuj:
// if (!$csrfOK) { http_response_code(403); echo json_encode(['status'=>'error','message'=>'CSRF invalid']); exit; }

// [3] Dane wejściowe
$productId   = (int)($_POST['product_id'] ?? 0);
$clientToken = (string)($_SESSION['client_token'] ?? ($_COOKIE['client_token'] ?? ''));
$ownerId     = (int)($_SESSION['owner_id'] ?? 0);

if ($productId <= 0 || $clientToken === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Brak danych do rezerwacji']);
    exit;
}

// Klient (scoped by owner); wg schematu mamy UNIQUE (owner_id, token) w clients
// i klucze indeksów po ownerze oraz tokenie. :contentReference[oaicite:1]{index=1}
if ($ownerId > 0) {
    $stmt = $pdo->prepare("SELECT id, owner_id FROM clients WHERE token = ? AND owner_id = ? LIMIT 1");
    $stmt->execute([$clientToken, $ownerId]);
} else {
    $stmt = $pdo->prepare("SELECT id, owner_id FROM clients WHERE token = ? LIMIT 1");
    $stmt->execute([$clientToken]);
}
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Nie znaleziono klienta / zaloguj się']);
    exit;
}
$ownerId = (int)$client['owner_id'];

// Produkt musi być aktywny danego ownera
$prod = $pdo->prepare("SELECT id FROM products WHERE id = ? AND owner_id = ? AND active = 1 LIMIT 1");
$prod->execute([$productId, $ownerId]);
if (!$prod->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Produkt niedostępny']);
    exit;
}

// [4] Rate-limit (30s) – na podstawie ostatniego rekordu
$rl = $pdo->prepare("
  SELECT last_request_at FROM product_reservations
  WHERE owner_id=? AND client_id=? AND product_id=?
  ORDER BY last_request_at DESC LIMIT 1
");
$rl->execute([$ownerId, (int)$client['id'], $productId]);
$last = $rl->fetchColumn();
if ($last && (time() - strtotime((string)$last)) < 30) {
    // Cichy sukces – nic nie psujemy, ale nie spamujemy
    echo json_encode([
        'status'        => 'ok',
        'message'       => 'Rezerwacja odświeżona',
        'rate_limited'  => true
    ]);
    exit;
}

// [5] UPSERT rezerwacji
// Struktura tabeli i klucze: UNIQUE (owner_id, client_id, product_id), status enum z wartościami:
// 'oczekuje','powiadomiony','anulowany','zrealizowany'. :contentReference[oaicite:2]{index=2}
$ins = $pdo->prepare("
  INSERT INTO product_reservations
    (owner_id, client_id, product_id, status, request_count, last_request_at, created_at, updated_at)
  VALUES
    (:o, :c, :p, 'oczekuje', 1, NOW(), NOW(), NOW())
  ON DUPLICATE KEY UPDATE
    status          = IF(status='anulowany','oczekuje', status),
    request_count   = request_count + 1,
    last_request_at = NOW(),
    updated_at      = NOW()
");

$ok = $ins->execute([
    ':o' => $ownerId,
    ':c' => (int)$client['id'],
    ':p' => $productId,
]);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Błąd zapisu rezerwacji']);
    exit;
}

// [6] Sukces
echo json_encode([
    'status'  => 'ok',
    'message' => '📦 Produkt dopisany do listy rezerwacji',
    'data'    => [
        'product_id' => $productId,
        'client_id'  => (int)$client['id']
    ]
]);
