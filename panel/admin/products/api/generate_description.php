<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json');

$product_id = (int)($_GET['id'] ?? 0);
if (!$product_id) {
    echo json_encode(['success' => false, 'error' => 'Brak ID']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo json_encode(['success' => false, 'error' => 'Nie znaleziono']);
    exit;
}

function generateProductDescription($p) {
    $lines = ["🛁 *{$p['name']}*"];
    if (!empty($p['code'])) $lines[] = "Kod produktu: `{$p['code']}`";
    if (!empty($p['price'])) $lines[] = "Cena: **" . number_format($p['price'], 2) . " zł**";
    if (!empty($p['weight'])) $lines[] = "Waga: {$p['weight']} kg";
    if (!empty($p['twelve_nc'])) $lines[] = "Kod 12NC: `{$p['twelve_nc']}`";
    $lines[] = "Opis automatyczny. Zmieniaj śmiało!";
    return implode("\n", $lines);
}

echo json_encode([
  'success' => true,
  'description' => generateProductDescription($product)
]);
