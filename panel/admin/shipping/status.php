<?php
// 1. opis czynności lub funkcji
// Ręczne odświeżenie statusu przesyłki z Furgonetki:
// - GET/POST id
// - wołamy API -> mapujemy status -> update shipping_labels.status
// - zapisujemy synced_at, ewentualny error_message

declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/furgonetka_auth.php';

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
header('Content-Type: application/json; charset=utf-8');

function json_out($data, int $code = 200) {
  if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); }
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}

try {
  if ($owner_id <= 0) json_out(['ok'=>false, 'error'=>'Brak kontekstu właściciela'], 403);
  $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
  if ($id <= 0) json_out(['ok'=>false, 'error'=>'Brak id'], 422);

  // (POST) opcjonalny CSRF
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
      json_out(['ok'=>false,'error'=>'CSRF'], 403);
    }
  }

  $st = $pdo->prepare("SELECT id, provider, external_id, status FROM shipping_labels WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $label = $st->fetch(PDO::FETCH_ASSOC);
  if (!$label) json_out(['ok'=>false,'error'=>'Etykieta nie istnieje'], 404);
  if ($label['provider'] !== 'furgonetka') json_out(['ok'=>false,'error'=>'Nieobsługiwany provider'], 400);
  if (empty($label['external_id'])) json_out(['ok'=>false,'error'=>'Brak external_id'], 400);

  $token = getFurgonetkaToken($owner_id);

  // TODO: FurgonetkaClient::getStatus($token, $label['external_id'])
  $extStatus = null; // np. "created", "label_generated", "in_delivery", "delivered", "canceled", "error"
  $extPayload = [];  // dopisz tu raw payload do logów jeżeli chcesz

  if ($extStatus === null) {
    // zapisz błąd (brak implementacji)
    $pdo->prepare("UPDATE shipping_labels SET error_message=?, synced_at=NOW(), updated_at=NOW() WHERE id=?")
        ->execute(['Brak implementacji klienta Furgonetki (getStatus).', $id]);
    json_out(['ok'=>false,'error'=>'Brak implementacji klienta Furgonetki (getStatus).'], 500);
  }

  // 2. mapowanie statusów zewnętrznych -> nasze
  $map = [
    'created'         => 'ordered',
    'label_generated' => 'label_ready',
    'in_delivery'     => 'in_transit',
    'delivered'       => 'delivered',
    'canceled'        => 'canceled',
    'error'           => 'error',
  ];
  $newStatus = $map[$extStatus] ?? $label['status'];

  $pdo->prepare("UPDATE shipping_labels SET status=?, synced_at=NOW(), error_message=NULL, updated_at=NOW() WHERE id=?")
      ->execute([$newStatus, $id]);

  // opcjonalnie: log surowego payloadu (skrócony)
  // wlog('shipping.status', ['id'=>$id,'from'=>$label['status'],'to'=>$newStatus,'ext'=>$extStatus]);

  json_out(['ok'=>true, 'id'=>$id, 'status'=>$newStatus, 'ext'=>$extStatus]);
} catch (Throwable $e) {
  // wlog('shipping.status', ['error'=>$e->getMessage(), 'id'=>$id ?? null]);
  json_out(['ok'=>false,'error'=>$e->getMessage()], 500);
}
