<?php
ob_start(); // zabezpieczenie przed "headers already sent"

require_once __DIR__ . '/furgonetka_api.php';
require_once __DIR__ . '/../../includes/db.php';

$orderId = $_GET['order_id'] ?? null;
if (!$orderId) {
    die("Brak ID zamówienia");
}

// Pobierz dane zamówienia
$stmt = $pdo->prepare("
    SELECT o.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone
    FROM orders o
    JOIN clients c ON o.client_id = c.id
    WHERE o.id = :id
");
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Nie znaleziono zamówienia");
}

try {
    $furgonetka = new FurgonetkaAPI();

    // Przygotowanie danych przesyłki zgodnie z dokumentacją integracji własnej
    $shipmentData = [
        "service_code" => "DPD", // lub dynamicznie z zamówienia
        "receiver" => [
            "name"    => $order['client_name'],
            "email"   => $order['client_email'],
            "phone"   => $order['client_phone'],
            "address" => [
                "street"     => $order['shipping_street'],
                "post_code"  => $order['shipping_postcode'],
                "city"       => $order['shipping_city'],
                "country"    => "PL"
            ]
        ],
        "parcels" => [
            ["weight" => 1.0] // lub policzone z produktów
        ]
    ];

    $result = $furgonetka->createShipment($shipmentData);

    // Po sukcesie możemy np. zapisać numer przesyłki do bazy
    $stmt = $pdo->prepare("UPDATE orders SET tracking_number = :tn WHERE id = :id");
    $stmt->execute([
        'tn' => $result['tracking_number'] ?? null,
        'id' => $orderId
    ]);

    header("Location: /admin/orders/view.php?id=" . $orderId . "&success=shipment_created");
    exit;
} catch (Exception $e) {
    ob_end_clean(); // czyścimy bufor, żeby header działał
    header("Location: /admin/orders/view.php?id=" . $orderId . "&error=" . urlencode($e->getMessage()));
    exit;
}
