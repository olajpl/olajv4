<?php
// checkout/complete.php – finalizacja zamówienia i inicjalizacja płatności (zgodnie z DB)
declare(strict_types=1);

require_once __DIR__ . '/../includes/checkout_loader.php'; // ustawia $pdo, $checkout_token, sesję

// 1) Paczka + kontekst zamówienia
$st = $pdo->prepare("
  SELECT 
    og.id               AS order_group_id,
    og.order_id,
    og.payment_method_id,
    og.shipping_method_id,
    og.checkout_token,
    o.owner_id
  FROM order_groups og
  JOIN orders o ON o.id = og.order_id
  WHERE og.checkout_token = ?
  LIMIT 1
");
$st->execute([$checkout_token]);
$group = $st->fetch(PDO::FETCH_ASSOC);
if (!$group) {
    http_response_code(404);
    exit('Nie znaleziono paczki.');
}

$order_group_id     = (int)$group['order_group_id'];
$order_id           = (int)$group['order_id'];
$payment_method_id  = $group['payment_method_id'] ? (int)$group['payment_method_id'] : null;
$shipping_method_id = $group['shipping_method_id'] ? (int)$group['shipping_method_id'] : null;
$owner_id           = (int)$group['owner_id'];

// 2) Aktualizacja metody płatności (jeśli przyszła w POST)
if (
    isset($_POST['payment_method_id']) &&
    (int)$_POST['payment_method_id'] > 0 &&
    (int)$_POST['payment_method_id'] !== (int)$payment_method_id
) {
    $payment_method_id = (int)$_POST['payment_method_id'];
    $pdo->prepare("UPDATE order_groups SET payment_method_id = ? WHERE id = ?")
        ->execute([$payment_method_id, $order_group_id]);
}

// 3) Pozycje paczki (snapshot: price -> unit_price -> products.price)
$st = $pdo->prepare("
  SELECT 
    oi.id,
    COALESCE(oi.quantity, 0) AS quantity,
    COALESCE(oi.price, oi.unit_price, p.price, 0) AS unit_price
  FROM order_items oi
  LEFT JOIN products p ON p.id = oi.product_id
  WHERE oi.order_group_id = ?
");
$st->execute([$order_group_id]);
$order_items = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$order_items) {
    http_response_code(400);
    exit('Brak produktów w paczce.');
}

// 4) Jeżeli znamy metodę płatności – przygotuj płatność
if ($payment_method_id) {
    // 4a) Definicja metody
    $st = $pdo->prepare("SELECT id, owner_id, name, type FROM payment_methods WHERE id = ? LIMIT 1");
    $st->execute([$payment_method_id]);
    $payment_method = $st->fetch(PDO::FETCH_ASSOC);

    if ($payment_method) {
        // 4b) Suma pozycji
        $items_total = 0.0;
        foreach ($order_items as $it) {
            $qty   = (int)($it['quantity'] ?? 0);
            $price = (float)($it['unit_price'] ?? 0.0);
            $items_total += $price * $qty;
        }

        // 4c) Koszt dostawy tylko dla PIERWSZEJ paczki w tym orderze
        $st = $pdo->prepare("SELECT id FROM order_groups WHERE order_id = ? ORDER BY created_at ASC, id ASC LIMIT 1");
        $st->execute([$order_id]);
        $first_group_id = (int)$st->fetchColumn();

        $shipping_cost = 0.0;
        if ($order_group_id === $first_group_id) {
            if ($shipping_method_id) {
                // z wybranej metody (kolumna default_price w schemacie)
                $st = $pdo->prepare("SELECT COALESCE(default_price, 0) FROM shipping_methods WHERE id = ? LIMIT 1");
                $st->execute([$shipping_method_id]);
                $shipping_cost = (float)$st->fetchColumn();
            } else {
                // fallback: najtańsza aktywna właściciela
                $st = $pdo->prepare("SELECT COALESCE(MIN(default_price), 0) FROM shipping_methods WHERE owner_id = ? AND active = 1");
                $st->execute([$owner_id]);
                $shipping_cost = (float)$st->fetchColumn();
            }
        }

        $total_amount = $items_total + $shipping_cost;

        // 4d) Status płatności zgodny z ENUM `payments.status`
        // 'online'  -> 'rozpoczęta'
        // 'pobranie'-> 'brak płatności'
        // 'przelew' / 'gotówka' / inne -> 'oczekujące'
        $pmType = (string)$payment_method['type'];
        $status = ($pmType === 'online')
            ? 'rozpoczęta'
            : (($pmType === 'pobranie') ? 'brak płatności' : 'oczekujące');

        // 4e) Idempotencja – nie duplikuj „startowych” płatności dla paczki
        $st = $pdo->prepare("
      SELECT id 
      FROM payments 
      WHERE order_group_id = ? 
        AND status IN ('rozpoczęta','oczekujące','brak płatności')
      ORDER BY id DESC 
      LIMIT 1
    ");
        $st->execute([$order_group_id]);
        $existing_payment_id = $st->fetchColumn();

        $pdo->beginTransaction();
        try {
            if (!$existing_payment_id) {
                // 4f) Insert do payments (kolumny zgodne z tabelą)
                $ins = $pdo->prepare("
          INSERT INTO payments (
            order_id, order_group_id, payment_method_id,
            method, currency, amount, status, checkout_token, created_at
          ) VALUES (
            :order_id, :order_group_id, :payment_method_id,
            :method, 'PLN', :amount, :status, :checkout_token, NOW()
          )
        ");
                $ins->execute([
                    'order_id'          => $order_id,
                    'order_group_id'    => $order_group_id,
                    'payment_method_id' => $payment_method_id,
                    'method'            => $pmType,
                    'amount'            => $total_amount,
                    'status'            => $status,
                    'checkout_token'    => $checkout_token,
                ]);
            }

            // 4g) Oznacz checkout jako zakończony
            $pdo->prepare("UPDATE order_groups SET checkout_completed = 1 WHERE id = ?")
                ->execute([$order_group_id]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            exit('Błąd finalizacji płatności.');
        }

        // 4h) Przekierowanie
        if ($pmType === 'online') {
            header("Location: /payments/start.php?token=" . urlencode($checkout_token));
            exit;
        }
    }
}
function triggerCW(string $checkout_token): void
{
    try {
        $ch = curl_init('https://panel.olaj.pl/api/cw/trigger_checkout.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['checkout_token' => $checkout_token],
            CURLOPT_TIMEOUT => 3,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        // Możesz dodać logg() jeśli chcesz logować odpowiedź
    } catch (Throwable $e) {
        // Nie przerywamy użytkownikowi – ciche logowanie ew. błędu
    }
}

// np. po $_SESSION['checkout']['checkout_token']
triggerCW($_SESSION['checkout']['checkout_token'] ?? '');

// 5) Metody offline (przelew/pobranie/gotówka/itd.): podziękowanie
header("Location: thank_you.php?token=" . urlencode($checkout_token) . "&pending=1");
exit;
