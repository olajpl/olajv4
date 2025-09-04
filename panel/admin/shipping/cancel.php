<?php
// 1. opis czynności lub funkcji
// Anulowanie wysyłki w Furgonetce dla wiersza shipping_labels:
// - POST z CSRF
// - sprawdzenie statusu (nie anulujemy po in_transit/delivered)
// - wywołanie API cancel
// - update: status='canceled', canceled_at=NOW()

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
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'Method not allowed'], 405);
  if ($owner_id <= 0) json_out(['ok'=>false,'error'=>'Brak kontekstu właściciela'], 403);

  $csrf = $_POST['csrf'] ?? '';
  if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    json_out(['ok'=>false,'error'=>'CSRF'], 403);
  }

  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) json_out(['ok'=>false,'error'=>'Brak id'], 422);

  $st = $pdo->prepare("SELECT * FROM shipping_labels WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $label = $st->fetch(PDO::FETCH_ASSOC);
  if (!$label) json_out(['ok'=>false,'error'=>'Etykieta nie istnieje'], 404);
  if ($label['provider'] !== 'furgonetka') json_out(['ok'=>false,'error'=>'Nieobsługiwany provider'], 400);

  $blocked = ['in_transit','delivered','canceled'];
  if (in_array($label['status'], $blocked, true)) {
    json_out(['ok'=>false,'error'=>'Przesyłki w tym statusie nie można anulować'], 400);
  }

  $token = getFurgonetkaToken($owner_id);

  // TODO: FurgonetkaClient::cancel($token, $label['external_id'])
  $ok = null; // placeholder wynik API
  if ($ok !== true) {
    // opcjonalnie: zapisz błąd
    $pdo->prepare("UPDATE shipping_labels SET error_message=?, updated_at=NOW() WHERE id=?")
        ->execute(['Anulowanie niezaimplementowane', $id]);
    json_out(['ok'=>false,'error'=>'Brak implementacji klienta Furgonetki (cancel).'], 500);
  }

  $pdo->prepare("UPDATE shipping_labels SET status='canceled', canceled_at=NOW(), updated_at=NOW() WHERE id=?")
      ->execute([$id]);

  json_out(['ok'=>true, 'id'=>$id, 'status'=>'canceled']);
} catch (Throwable $e) {
  // wlog('shipping.cancel', ['error'=>$e->getMessage(), 'id'=>$id ?? null]);
  json_out(['ok'=>false,'error'=>$e->getMessage()], 500);
}
