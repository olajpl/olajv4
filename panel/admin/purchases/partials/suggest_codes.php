<?php
// 1. opis czynności lub funkcji
// Endpoint sugerujący wolne kody produktu na podstawie prefixu i już użytych kodów.
// Zwraca TYLKO listę <span class="suggested-code">KOD</span> do wklejenia w modal.
// Użycie: POST prefix=ABC&used=ABC001,ABC002

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// 2) Dostęp i nagłówki
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
if (!$owner_id) { http_response_code(403); echo '❌'; exit; }

// 3) Wejście: prefix + lista już użytych (z formularza na stronie)
$prefix = strtoupper((string)($_POST['prefix'] ?? ''));
$prefix = preg_replace('/[^A-Z0-9]/', '', $prefix);
$prefix = substr($prefix, 0, 16);

// Fallback, żeby nie generować „gołych” 000/001 bez liter
if ($prefix === '') $prefix = 'P';

$used_codes = [];
if (!empty($_POST['used'])) {
  // CSV -> tablica; trzymamy uppercase dla spójności
  $used_codes = array_filter(array_map(function($v){
    $v = strtoupper(trim($v));
    return preg_replace('/[^A-Z0-9]/', '', $v);
  }, explode(',', (string)$_POST['used'])));
}

// 4) Pobierz istniejące kody z danym prefixem (LIKE 'PREFIX%')
$stmt = $pdo->prepare("
  SELECT code
  FROM products
  WHERE owner_id = ?
    AND code LIKE CONCAT(?, '%')
");
$stmt->execute([$owner_id, $prefix]);
$db_codes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 5) Zbiór zajętych (DB + aktualnie wpisane na stronie)
$all_used = array_unique(array_merge($db_codes ?: [], $used_codes));

// 6) Znajdź największy numer sufiksu: ^PREFIX(\d{3,})$
$re  = '/^' . preg_quote($prefix, '/') . '(\d{3,})$/';
$max = 0;
foreach ($all_used as $code) {
  if (preg_match($re, $code, $m)) {
    $num = (int)$m[1];
    if ($num > $max) $max = $num;
  }
}

// 7) Generuj 10 propozycji (PREFIX + 3+ cyfry), unikając kolizji
$want  = 10;
$made  = 0;
$start = $max + 1;
$out   = [];

while ($made < $want && $start < $max + 10000) {
  $code = $prefix . str_pad((string)$start, 3, '0', STR_PAD_LEFT);
  $start++;

  if (!in_array($code, $all_used, true)) {
    $out[] = '<span class="suggested-code cursor-pointer bg-blue-100 px-2 py-1 rounded hover:bg-blue-200">'
           . htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
           . '</span>';
    $made++;
  }
}

// 8) Render
if ($made === 0) {
  echo '<span class="text-gray-500">Brak propozycji dla prefixu '.htmlspecialchars($prefix, ENT_QUOTES).'</span>';
  exit;
}

echo implode("\n", $out);
