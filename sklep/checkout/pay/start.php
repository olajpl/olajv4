<?php
// checkout/start.php — inicjacja płatności online
declare(strict_types=1);
ob_start();
session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/checkout_loader.php';

// 1) Kontekst
$checkout_token = resolveAnyToken();
if (!$checkout_token) {
  http_response_code(400);
  exit('Brak checkout_token.');
}

// Pobierz grupę i zamówienie
$st = $pdo->prepare("
  SELECT o.id AS order_id, o.owner_id, o.client_id,
         og.id AS order_group_id, og.checkout_token,
         o.order_status, og.checkout_completed
  FROM order_groups og
  JOIN orders o ON o.id = og.order_id
  WHERE og.checkout_token = :t
  LIMIT 1
");
$st->execute(['t' => $checkout_token]);
$ctx = $st->fetch(PDO::FETCH_ASSOC);
if (!$ctx) {
  http_response_code(404);
  exit('Nie znaleziono paczki.');
}

// 2) Suma do zapłaty (produkty + ew. dostawa – tu dokładamy w razie potrzeby)
$sumItems = (float)$pdo->fetchColumn(
  $pdo->query("SELECT 0") // placeholder
) ?: 0.0;

$qs = $pdo->prepare("SELECT COALESCE(SUM(quantity * unit_price),0) FROM order_items WHERE order_group_id=:g");
$qs->execute(['g' => (int)$ctx['order_group_id']]);
$sumItems = (float)$qs->fetchColumn();

// (opcjonalnie) dolicz cenę dostawy z orders.shipping_id → shipping_methods.default_price
$shipping = 0.0;
if (!empty($ctx['order_id'])) {
  $ship = $pdo->prepare("
    SELECT sm.default_price
    FROM orders o
    LEFT JOIN shipping_methods sm ON sm.id = o.shipping_id
    WHERE o.id = :oid
    LIMIT 1
  ");
  $ship->execute(['oid' => (int)$ctx['order_id']]);
  $shipping = (float)($ship->fetchColumn() ?: 0.0);
}

$amount = round($sumItems + $shipping, 2);

// 3) Upsert draft payment po checkout_token (lub order_group_id)
$sel = $pdo->prepare("SELECT id FROM payments WHERE checkout_token = :t LIMIT 1");
$sel->execute(['t' => $checkout_token]);
$pid = (int)($sel->fetchColumn() ?: 0);

if ($pid) {
  $upd = $pdo->prepare("
    UPDATE payments
    SET amount = :a, currency='PLN', status = IF(status='brak płatności','oczekujące',status), updated_at = NOW()
    WHERE id = :id
  ");
  $upd->execute(['a' => $amount, 'id' => $pid]);
} else {
  $ins = $pdo->prepare("
    INSERT INTO payments (order_id, order_group_id, checkout_token, currency, amount, status, created_at)
    VALUES (:oid, :gid, :t, 'PLN', :a, 'oczekujące', NOW())
  ");
  $ins->execute([
    'oid' => (int)$ctx['order_id'],
    'gid' => (int)$ctx['order_group_id'],
    't'   => $checkout_token,
    'a'   => $amount
  ]);
  $pid = (int)$pdo->lastInsertId();
}

// 4) Idempotency key + reference
$idemp = bin2hex(random_bytes(16));
$ref   = 'OLAJ-' . strtoupper(bin2hex(random_bytes(5)));

$pdo->prepare("UPDATE payments SET idempotency_key=:ik, reference_code=:ref, status='rozpoczęta', updated_at=NOW() WHERE id=:id")
  ->execute(['ik' => $idemp, 'ref' => $ref, 'id' => $pid]);

// 5) Przygotuj URL do bramki (TU podłączysz realnego providera)
// Przykładowa pętla: return do /checkout/return.php
$host = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$returnUrl = $host . '/checkout/return.php?token=' . urlencode($checkout_token) . '&ref=' . urlencode($ref);

// W prawdziwej integracji tu robisz call do API i dostajesz redirect_url z bramki
$redirectUrl = $returnUrl; // tymczasowo: „udawana” bramka wraca od razu

header('Location: ' . $redirectUrl);
exit;
