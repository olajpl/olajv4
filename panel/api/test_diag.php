<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$base = dirname(__DIR__);
require_once $base . '/../includes/db.php';
require_once $base . '/../engine/Orders/OrderEngine.php';

use Engine\Orders\OrderEngine;

$out = ['ok' => false, 'steps' => []];

try {
    // 1) weź jakiegoś klienta
    $clientId = (int)($pdo->query("SELECT id FROM clients ORDER BY id DESC LIMIT 1")->fetchColumn());
    if ($clientId <= 0) throw new RuntimeException('Brak clienta. Najpierw wyślij DM.');

    // 2) znajdź produkt (po code lub pierwszy z products)
    $pid = null;
    foreach (
        [
            "SELECT id FROM products WHERE code<>'' ORDER BY id DESC LIMIT 1",
            "SELECT id FROM products ORDER BY id DESC LIMIT 1"
        ] as $sql
    ) {
        $pid = $pdo->query($sql)->fetchColumn();
        if ($pid) {
            $pid = (int)$pid;
            break;
        }
    }
    if (!$pid) throw new RuntimeException('Brak produktu w `products`. Dodaj cokolwiek na próbę.');

    $ownerId = 1;

    $eng = new OrderEngine($pdo);
    $res = $eng->addOrderItem([
        'owner_id'   => $ownerId,
        'client_id'  => $clientId,
        'product_id' => $pid,
        'code'       => null,
        'quantity'   => 1,
        'source_type' => 'probe'
    ]);

    $out['engine_result'] = $res;

    // 3) Podsumowanie z bazy
    $out['orders_tail'] = $pdo->query("SELECT id, owner_id, client_id, order_status, checkout_token, checkout_completed, created_at
                                     FROM orders ORDER BY id DESC LIMIT 3")->fetchAll();
    $out['items_tail']  = $pdo->query("SELECT id, order_id, product_id, quantity, unit_price, created_at
                                     FROM order_items ORDER BY id DESC LIMIT 5")->fetchAll();

    $out['ok'] = (bool)($res['success'] ?? false);
} catch (Throwable $e) {
    $out['err'] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
