<?php
// admin/live/ajax/ajax_client_search.php
require_once __DIR__ . '/__live_boot.php';

$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';

try {
  header('Content-Type: application/json; charset=utf-8');

  $owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
  $q = trim((string)($_GET['q'] ?? ''));
  if ($q === '') {
    echo json_encode(['results' => []]);
    exit;
  }

  $like = "%{$q}%";

  // 🔎 Szukamy po: name / email / phone (i opcjonalnie token)
  $sql = "
    SELECT
      c.id,
      c.name,
      c.email,
      c.phone,
      c.last_seen,
      TRIM(
        CONCAT(
          COALESCE(c.name, ''),
          CASE WHEN COALESCE(c.email,'') <> '' THEN CONCAT(' <', c.email, '>') ELSE '' END,
          CASE WHEN COALESCE(c.phone,'') <> '' THEN CONCAT(' — ', c.phone) ELSE '' END
        )
      ) AS text
    FROM clients c
    WHERE 1=1
      " . ($owner_id > 0 ? " AND c.owner_id = :oid " : "") . "
      AND (
            c.name  LIKE :q1
        OR  c.email LIKE :q2
        OR  c.phone LIKE :q3
        OR  c.token LIKE :q4
      )
    ORDER BY COALESCE(c.last_seen, '1970-01-01') DESC, c.id DESC
    LIMIT 20
  ";

  $st = $pdo->prepare($sql);
  if ($owner_id > 0) $st->bindValue(':oid', $owner_id, PDO::PARAM_INT);
  $st->bindValue(':q1', $like, PDO::PARAM_STR);
  $st->bindValue(':q2', $like, PDO::PARAM_STR);
  $st->bindValue(':q3', $like, PDO::PARAM_STR);
  $st->bindValue(':q4', $like, PDO::PARAM_STR);
  $st->execute();

  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $results = array_map(function($r){
    return [
      'id'   => (int)$r['id'],
      'text' => $r['text'] ?: ('Klient #' . (int)$r['id']),
      // surowe pola – przydadzą się do dodatkowych etykiet
      'name'  => $r['name'],
      'email' => $r['email'],
      'phone' => $r['phone'],
    ];
  }, $rows);

  echo json_encode(['results' => $results]);
} catch (Throwable $e) {
  http_response_code(200); // Select2 nie lubi 500
  echo json_encode([
    'results' => [],
    'error'   => $DEBUG ? $e->getMessage() : 'Błąd wyszukiwania klientów'
  ]);
}

