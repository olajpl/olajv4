<?php

declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$live_id = (int)($_GET['live_id'] ?? 0);
$mode = $_GET['mode'] ?? 'clients';
$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$live_id = (int)($_GET['live_id'] ?? 0);
if ($live_id <= 0 || $owner_id <= 0) {
  echo json_encode(['error' => 'Brak live_id lub owner_id']);
  exit;
}

if (!in_array($mode, ['clients', 'products'], true)) {
  echo json_encode(['error' => 'Nieprawidłowy tryb']);
  exit;
}

try {
  if ($mode === 'clients') {
    $sql = "
    SELECT
      lt.client_id,
      COALESCE(c.name, CONCAT('Klient #', lt.client_id)) AS client_name,
      COALESCE(c.phone, '') AS phone,
      COUNT(*) AS count_items,
      SUM(lt.qty) AS sum_qty,
      SUM(lt.qty * lt.price) AS sum_value,
      JSON_ARRAYAGG(JSON_OBJECT(
        'id', lt.id,
        'product_id', lt.product_id,
        'product_name', lt.name,
        'custom_name', lt.name,
        'sku', lt.sku,
        'qty', lt.qty,
        'price', lt.price,
        'vat_rate', lt.vat_rate,
        'note', NULL
      )) AS items_json
    FROM live_temp lt
    LEFT JOIN clients c ON c.id = lt.client_id
    WHERE lt.owner_id = :owner_id AND lt.live_id = :live_id AND lt.transferred_at IS NULL
    GROUP BY lt.client_id
    ORDER BY client_name ASC, lt.client_id ASC
  ";
  } else {
    // mode === 'products'
    $sql = "
      SELECT
        lt.product_id,
        lt.name AS product_name,
        lt.sku,
        COUNT(DISTINCT lt.client_id) AS count_clients,
        SUM(lt.qty) AS sum_qty,
        SUM(lt.qty * lt.price) AS sum_value,
        JSON_ARRAYAGG(JSON_OBJECT(
          'client_id', lt.client_id,
          'client_name', c.name,
          'qty', lt.qty,
          'price', lt.price,
          'note', NULL
        )) AS items_json
      FROM live_temp lt
      LEFT JOIN clients c ON c.id = lt.client_id
      WHERE lt.owner_id = :owner_id AND lt.live_id = :live_id AND lt.transferred_at IS NULL
      GROUP BY lt.product_id, lt.name, lt.sku
      ORDER BY lt.name ASC
    ";
  }

  $stmt = $pdo->prepare($sql);
  $stmt->execute(['owner_id' => $owner_id, 'live_id' => $live_id]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as &$row) {
    $row['rows'] = json_decode($row['items_json'], true);
    unset($row['items_json']);
  }


  echo json_encode(['groups' => $rows]);
} catch (Throwable $e) {
  echo json_encode(['error' => 'Błąd serwera: ' . $e->getMessage()]);
  exit;
}
