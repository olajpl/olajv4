<?php
// admin/live/ajax/ajax_product_search.php
require_once __DIR__ . '/__live_boot.php';
header('Content-Type: application/json; charset=utf-8');

$DEBUG = (isset($_GET['debug']) && $_GET['debug'] == '1');

try {
  // --- LIKE helpers (ESCAPE '\') ---
  function like_escape(string $s): string {
    $s = str_replace('\\','\\\\',$s);
    return str_replace(['%','_'], ['\\%','\\_'], $s);
  }
  function like_any(string $s): string  { return '%'.like_escape($s).'%'; }
  function like_pref(string $s): string { return like_escape($s).'%'; }

  $owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
  $q = trim((string)($_GET['q'] ?? ''));
  if ($q === '') { echo json_encode(['results'=>[]], JSON_UNESCAPED_UNICODE); exit; }

  // — 100% zgodne z Twoją tabelą products —
  // --- ZAMIANA TWOJEGO BLOKU NA TEN ---

$searchCols = ['p.name','p.code','p.sku','p.ean','p.twelve_nc'];

// scoring: exact > prefix > substring (unikalne placeholdery per kolumna)
$scoreParts = [];
$whereLike  = [];
foreach ($searchCols as $i => $c) {
  $scoreParts[] = "IF($c = :eq$i, 3, 0)";
  $scoreParts[] = "IF($c LIKE :pref$i ESCAPE '\\\\', 2, 0)";
  $scoreParts[] = "IF($c LIKE :sub$i  ESCAPE '\\\\', 1, 0)";
  $whereLike[]  = "$c LIKE :q$i ESCAPE '\\\\'";
}
$scoreExpr = '(' . implode(' + ', $scoreParts) . ') AS score';

$nameWithSku = "CONCAT(p.name, CASE WHEN COALESCE(p.sku,'')<>'' THEN CONCAT(' (',p.sku,')') ELSE '' END)";

$sql = "
  SELECT
    p.id, p.name, p.code, p.sku, p.ean, p.twelve_nc,
    p.unit_price AS price, p.vat_rate,
    p.stock_cached, p.stock_reserved, p.stock_reserved_cached, p.stock_available,
    $nameWithSku AS text,
    $scoreExpr
  FROM products p
  WHERE 1=1
    " . ($owner_id>0 ? " AND p.owner_id = :oid " : "") . "
    AND p.deleted_at IS NULL
    AND COALESCE(p.active,1) = 1
    AND COALESCE(p.is_active,1) = 1
    AND (" . implode(' OR ', $whereLike) . ")
  ORDER BY score DESC, p.name ASC
  LIMIT 20
";

$st = $pdo->prepare($sql);
if ($owner_id>0) $st->bindValue(':oid', $owner_id, PDO::PARAM_INT);

foreach ($searchCols as $i => $_) {
  // LIKE dla filtra kolumn (substring)
  $st->bindValue(":q$i",    '%'.like_escape($q).'%', PDO::PARAM_STR);
  // scoring
  $st->bindValue(":eq$i",    $q,                        PDO::PARAM_STR);
  $st->bindValue(":pref$i",  like_escape($q).'%',       PDO::PARAM_STR);
  $st->bindValue(":sub$i",   '%'.like_escape($q).'%',   PDO::PARAM_STR);
}


  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $results = array_map(function(array $r){
    $available = isset($r['stock_available'])
      ? (float)$r['stock_available']
      : (isset($r['stock_cached'],$r['stock_reserved_cached'])
          ? (float)$r['stock_cached'] - (float)$r['stock_reserved_cached']
          : null);
    return [
      'id'    => (int)$r['id'],
      'text'  => $r['text'] ?: $r['name'],
      'name'        => $r['name'],
      'code'        => $r['code'],
      'sku'         => $r['sku'],
      'ean'         => $r['ean'],
      'twelve_nc'   => $r['twelve_nc'],
      'vat_rate'    => isset($r['vat_rate']) ? (float)$r['vat_rate'] : null,
      'price'       => isset($r['price']) ? (float)$r['price'] : null,
      'stock_cached'            => isset($r['stock_cached']) ? (float)$r['stock_cached'] : null,
      'stock_reserved'          => isset($r['stock_reserved']) ? (float)$r['stock_reserved'] : null,
      'stock_reserved_cached'   => isset($r['stock_reserved_cached']) ? (float)$r['stock_reserved_cached'] : null,
      'stock_available'         => $available,
    ];
  }, $rows);

  $payload = ['results'=>$results];
  if ($DEBUG) {
    $payload['_debug'] = [
      'endpoint' => __FILE__,
      'owner_id' => $owner_id,
      'q'        => $q,
      'count'    => count($results),
      'sql'      => $sql
    ];
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  // 🔎 pełny wgląd po ?debug=1
  if ($DEBUG) {
    echo json_encode([
      'results' => [],
      'error'   => 'DB error: ' . $e->getMessage(),
      'trace'   => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE);
    return;
  }

  // 📝 log do centralnego loggera (jeśli masz includes/log.php)
  if (function_exists('logg')) {
    logg('error', 'live.product_search', 'Błąd wyszukiwarki produktów', [
      'owner_id' => (int)($_SESSION['user']['owner_id'] ?? 0),
      'q'        => $q ?? null,
    ], [
      'exception' => $e->getMessage(),
      'trace'     => $e->getTraceAsString(),
    ]);
  }
  echo json_encode(['results'=>[], 'error'=>'Błąd wyszukiwania produktów'], JSON_UNESCAPED_UNICODE);
}

