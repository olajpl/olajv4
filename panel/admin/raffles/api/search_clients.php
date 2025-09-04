<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $q        = trim((string)($_GET['q'] ?? ''));
  $draw_id  = (int)($_GET['draw_id'] ?? 0);
  $limit    = max(1, min(50, (int)($_GET['limit'] ?? 10)));
  $owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);

  // Fallback ownera po draw_id (jeśli nie ma w sesji)
  if ($owner_id <= 0 && $draw_id > 0) {
    $st = $pdo->prepare("SELECT owner_id FROM draws WHERE id = :id LIMIT 1");
    $st->execute([':id' => $draw_id]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $owner_id = (int)$row['owner_id'];
    }
  }

  if ($owner_id <= 0 || $q === '') {
    echo json_encode([]); // pusta odpowiedź (frontend to ogarnie)
    exit;
  }

  // allow_duplicates do ewentualnego wykluczenia już dodanych
  $allowDuplicates = 1;
  if ($draw_id > 0) {
    $st = $pdo->prepare("SELECT allow_duplicates FROM draws WHERE id = :id AND owner_id = :oid");
    $st->execute([':id' => $draw_id, ':oid' => $owner_id]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $allowDuplicates = (int)$row['allow_duplicates'];
    }
  }

  // Wzorzec LIKE (spacje → wildcard)
  $pattern = '%' . preg_replace('/\s+/', '%', $q) . '%';

  // Jeśli trzeba, przygotuj zbiór już dodanych klientów (żeby wykluczyć)
  $excludedIds = [];
  if ($draw_id > 0 && $allowDuplicates === 0) {
    $ex = $pdo->prepare("
            SELECT de.platform_id
            FROM draw_entries de
            WHERE de.draw_id = :did AND de.platform = 'client' AND de.platform_id IS NOT NULL
        ");
    $ex->execute([':did' => $draw_id]);
    // platform_id jest VARCHAR — zrzutujmy do int gdy się da
    foreach ($ex->fetchAll(PDO::FETCH_COLUMN, 0) as $pid) {
      $pid = is_numeric($pid) ? (int)$pid : null;
      if ($pid) $excludedIds[$pid] = true;
    }
  }

  // Główny SELECT
  $sql = "
    SELECT
      c.id,
      COALESCE(NULLIF(TRIM(c.name),''), CONCAT('Klient #', c.id)) AS name,
      c.email,
      c.phone,
      c.token
    FROM clients c
    WHERE c.owner_id = :oid
      AND (
            c.name  LIKE :q
         OR c.token LIKE :q
         OR c.email LIKE :q
         OR c.phone LIKE :q
         OR CAST(c.id AS CHAR) LIKE :q
      )
    ORDER BY name ASC, c.id ASC
    LIMIT {$limit}";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([':oid' => $owner_id, ':q' => $pattern]);

  $out = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $cid = (int)$r['id'];
    if (!empty($excludedIds) && isset($excludedIds[$cid])) {
      continue; // wyklucz — już jest na liście
    }
    $out[] = [
      'id'    => $cid,
      'name'  => (string)$r['name'],
      'email' => isset($r['email']) ? (string)$r['email'] : null,
      'phone' => isset($r['phone']) ? (string)$r['phone'] : null,
      'token' => isset($r['token']) ? (string)$r['token'] : null,
    ];
  }

  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
