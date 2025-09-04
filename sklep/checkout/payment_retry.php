<?php

declare(strict_types=1);
require_once __DIR__ . '/../includes/checkout_loader.php';
$checkout_token = resolveAnyToken();
if (!$checkout_token) {
  http_response_code(400);
  die('Brak checkout_token.');
}
$_SESSION['checkout_token'] = $checkout_token; // utrwalenie na dalsze kroki

// znajdź ostatnią metodę online paczki
$stmt = $pdo->prepare("
  SELECT pm.id
  FROM order_groups og
  JOIN payment_methods pm ON pm.id = og.payment_method_id
  WHERE og.id = :gid AND pm.type = 'online' AND pm.active = 1
  LIMIT 1
");
$stmt->execute(['gid' => $checkout['order_group_id']]);
$methodId = (int)($stmt->fetchColumn() ?: 0);

if ($methodId > 0) {
  // utwórz nowy rekord payments (retry)
  $amount = (float)($_GET['amount'] ?? 0); // lub przelicz jak w summary
  if ($amount <= 0) {
    // przelicz z DB:
    $sumStmt = $pdo->prepare("
        SELECT COALESCE(SUM(oi.price * oi.quantity),0) FROM order_items oi WHERE oi.order_group_id = :gid
      ");
    $sumStmt->execute(['gid' => $checkout['order_group_id']]);
    $totalProducts = (float)$sumStmt->fetchColumn();

    // dostawa jeśli 1. paczka:
    $g = $pdo->prepare("SELECT id FROM order_groups WHERE order_id = :oid ORDER BY id ASC LIMIT 1");
    $g->execute(['oid' => $checkout['order_id']]);
    $firstId = (int)$g->fetchColumn();

    $shipCost = 0;
    if ($firstId === (int)$checkout['order_group_id']) {
      $sc = $pdo->prepare("SELECT shipping_cost FROM orders WHERE id = :oid");
      $sc->execute(['oid' => $checkout['order_id']]);
      $shipCost = (float)$sc->fetchColumn();
    }
    $amount = $totalProducts + $shipCost;
  }

  $pdo->prepare("
    INSERT INTO payments (order_group_id, method_id, provider, status, amount, created_at, updated_at)
    SELECT :gid, pm.id, pm.provider, 'oczekujące', :amt, NOW(), NOW()
    FROM payment_methods pm WHERE pm.id = :pm
  ")->execute(['gid' => $checkout['order_group_id'], 'pm' => $methodId, 'amt' => $amount]);

  header('Location: /checkout/pay/start.php?token=' . urlencode((string)$checkout['token']) . '&pm=' . $methodId);
  exit;
}

http_response_code(404);
exit('Brak metody online do ponowienia.');
