<?php
// admin/live/ajax/ajax_product_search.php
require_once __DIR__ . '/__live_boot.php';

$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';

try {
  header('Content-Type: application/json; charset=utf-8');

  // owner id z sesji (jeśli nie ma – nie filtrujemy po ownerze)
  $owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
  $q = trim((string)($_GET['q'] ?? ''));
  if ($q === '') {
    echo json_encode(['results' => []]);
    exit;
  }

  $like = "%{$q}%";

  // 👇 zgodne z Twoją tabelą products
  $sql = "
    SELECT
      p.id,
      p.name,
      p.sku,
      p.code,
      p.barcode,
      p.twelve_nc,
      p.vat_rate,
      p.price,
      p.stock,
      p.stock_reserved,
      CONCAT(
        p.name,
        CASE WHEN COALESCE(p.sku,'') <> '' THEN CONCAT(' (', p.sku, ')') ELSE '' END
      ) AS text
    FROM products p
    WHERE 1=1
      AND (p.active = 1 OR p.active IS NULL)          -- jeśli chcesz pokazywać tylko aktywne
      " . ($owner_id > 0 ? " AND p.owner_id = :oid " : "") . "
      AND (
            p.name      LIKE :q1
        OR  p.sku       LIKE :q2
        OR  p.code      LIKE :q3
        OR  p.barcode   LIKE :q4
        OR  p.twelve_nc LIKE :q5
      )
    ORDER BY p.name ASC
    LIMIT 20
  ";

  $st = $pdo->prepare($sql);
  if ($owner_id > 0) $st->bindValue(':oid', $owner_id, PDO::PARAM_INT);
  $st->bindValue(':q1', $like, PDO::PARAM_STR);
  $st->bindValue(':q2', $like, PDO::PARAM_STR);
  $st->bindValue(':q3', $like, PDO::PARAM_STR);
  $st->bindValue(':q4', $like, PDO::PARAM_STR);
  $st->bindValue(':q5', $like, PDO::PARAM_STR);
  $st->execute();

  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // Select2 lubi { results: [ {id, text}, ... ] }
  // Dorzucam też surowe pola – przydają się później
  $results = array_map(function($r){
    return [
      'id'    => (int)$r['id'],
      'text'  => $r['text'] ?: $r['name'],
      // dane dodatkowe:
      'name'        => $r['name'],
      'sku'         => $r['sku'],
      'code'        => $r['code'],
      'barcode'     => $r['barcode'],
      'twelve_nc'   => $r['twelve_nc'],
      'vat_rate'    => $r['vat_rate'],
      'price'       => $r['price'],
      'stock'       => (int)$r['stock'],
      'stock_reserved' => (int)$r['stock_reserved'],
    ];
  }, $rows);

  echo json_encode(['results' => $results]);
} catch (Throwable $e) {
  http_response_code(200); // Select2 nie lubi 500
  echo json_encode([
    'results' => [],
    'error'   => $DEBUG ? $e->getMessage() : 'Błąd wyszukiwania produktów'
  ]);
}
