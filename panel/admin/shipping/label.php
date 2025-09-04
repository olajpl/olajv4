<?php
// 1. opis czynności lub funkcji
// Pobranie etykiety dla istniejącej przesyłki (shipping_labels.id) z Furgonetki:
// - walidacja CSRF (dla POST) lub bez dla GET pod podgląd (wewnętrzny panel)
// - pobranie tokena, wywołanie klienta, zapis PDF do storage/labels/{owner}/{id}.pdf
// - update: label_path, status='label_ready'
// - opcjonalnie zwrócenie PDF (download=1) lub JSON

declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
// opcjonalnie: require_once __DIR__ . '/../../includes/logger.php';
require_once __DIR__ . '/furgonetka_auth.php';   // token helper (placeholder)
$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);

header('Content-Type: application/json; charset=utf-8');

function json_out($data, int $code = 200) {
  if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); }
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}

try {
  if ($owner_id <= 0) json_out(['ok'=>false, 'error'=>'Brak kontekstu właściciela'], 403);

  $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
  if ($id <= 0) json_out(['ok'=>false, 'error'=>'Brak id etykiety'], 422);

  // (POST) – sprawdzamy CSRF; GET może służyć do podglądu w panelu
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
      json_out(['ok'=>false, 'error'=>'CSRF'], 403);
    }
  }

  // 2. wczytaj rekord
  $st = $pdo->prepare("SELECT * FROM shipping_labels WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $label = $st->fetch(PDO::FETCH_ASSOC);
  if (!$label) json_out(['ok'=>false, 'error'=>'Etykieta nie istnieje'], 404);
  if ((int)$label['order_id'] <= 0) json_out(['ok'=>false, 'error'=>'Brak order_id'], 400);
  if (!in_array($label['provider'], ['furgonetka'], true)) json_out(['ok'=>false, 'error'=>'Nieobsługiwany provider'], 400);

  // 3. przygotuj ścieżkę docelową
  $ownerDir = __DIR__ . '/../../storage/labels/' . $owner_id;
  if (!is_dir($ownerDir)) @mkdir($ownerDir, 0775, true);
  $pdfPath = $ownerDir . '/' . $id . '.pdf';

  // 4. jeśli już mamy, to tylko zwracamy
  if (empty($label['label_path']) || !is_file(__DIR__ . '/../../' . ltrim((string)$label['label_path'], '/'))) {
    // 4a. pobierz token + etykietę z API
    // TODO: zaimplementuj w furgonetka_auth.php -> getFurgonetkaToken(int $owner_id): string
    $token = getFurgonetkaToken($owner_id);

    // TODO: zrób prosty klient: FurgonetkaClient::getLabel($token, $label['external_id'])
    // Placeholder – symulacja odpowiedzi binarnej PDF:
    $pdfBinary = null; // <- tutaj wstaw wynik API (string z binarną zawartością PDF)
    if ($pdfBinary === null) {
      // w prawdziwej wersji zawołaj API, obsłuż błędy:
      // $resp = FurgonetkaClient::getLabel($token, $label['external_id']);
      // if (!$resp->ok) { update error_message; throw ... }
      throw new RuntimeException('Brak implementacji klienta Furgonetki (getLabel).');
    }

    // 4b. zapisz PDF
    if (file_put_contents($pdfPath, $pdfBinary) === false) {
      json_out(['ok'=>false, 'error'=>'Nie mogę zapisać PDF'], 500);
    }
    $relPath = 'storage/labels/' . $owner_id . '/' . $id . '.pdf';

    // 4c. update DB
    $up = $pdo->prepare("UPDATE shipping_labels SET label_path=?, status=IF(status='ordered','label_ready',status), updated_at=NOW() WHERE id=?");
    $up->execute([$relPath, $id]);
  } else {
    $pdfPath = __DIR__ . '/../../' . ltrim((string)$label['label_path'], '/');
  }

  // 5. zwrot: JSON albo bezpośredni PDF
  if (($_GET['download'] ?? '0') === '1') {
    if (!is_file($pdfPath)) json_out(['ok'=>false, 'error'=>'PDF nie istnieje'], 404);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="label-'.$id.'.pdf"');
    readfile($pdfPath); exit;
  } else {
    json_out(['ok'=>true, 'id'=>$id, 'label_path'=>str_replace(__DIR__ . '/../../', '', $pdfPath)]);
  }
} catch (Throwable $e) {
  // opcjonalnie: wlog('shipping.label', ['error'=>$e->getMessage(), 'id'=>$id ?? null]);
  json_out(['ok'=>false, 'error'=>$e->getMessage()], 500);
}
