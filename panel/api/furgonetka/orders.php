<?php
require_once __DIR__ . '/../../config/config.php';

$pdo = getPDO();

// Weryfikacja tokenu
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$expectedToken = 'twój_token_z_system_settings';

if ($auth !== "Bearer $expectedToken") {
  http_response_code(401);
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

// Pobierz zamówienia np. z ostatnich 7 dni ze statusem „Do wysyłki”
$stmt = $pdo->prepare("SELECT * FROM orders WHERE status = 'Do wysyłki' AND created_at >= NOW() - INTERVAL 7 DAY");
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sformatuj dane
$response = [];

foreach ($orders as $order) {
  $response[] = [
    'id' => $order['id'],
    'reference' => "ZAM-" . $order['id'],
    'recipient' => [
      'name' => $order['full_name'],
      'email' => $order['email'],
      'phone' => $order['phone'],
      'address' => [
        'street' => $order['street'] . ' ' . $order['building_number'],
        'flat_number' => $order['flat_number'],
        'postal_code' => $order['postal_code'],
        'city' => $order['city'],
        'country' => $order['country'] ?? 'PL',
      ]
    ]
  ];
}

header('Content-Type: application/json');
echo json_encode($response);
