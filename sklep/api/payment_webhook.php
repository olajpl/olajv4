<?php
// api/payment_webhook.php – webhook potwierdzenia płatności od operatora
require_once __DIR__ . '/../includes/db.php';

// 🔐 Weryfikacja (zależna od operatora płatności – tu uproszczona)
$reference_code = $_POST['reference_code'] ?? '';
$status = $_POST['status'] ?? '';

if (!$reference_code || !$status) {
    http_response_code(400);
    echo "Błąd: brak danych.";
    exit;
}

// Znajdź płatność po kodzie referencyjnym
$stmt = $pdo->prepare("SELECT * FROM payments WHERE reference_code = :ref");
$stmt->execute(['ref' => $reference_code]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    http_response_code(404);
    echo "Płatność nieznaleziona.";
    exit;
}

// Zaktualizuj status, jeśli pasuje
if ($status === 'opłacone') {
    $stmt = $pdo->prepare("UPDATE payments SET status = 'opłacone', paid_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => $payment['id']]);
    http_response_code(200);
    echo "✅ Zaktualizowano status płatności.";
} else {
    http_response_code(400);
    echo "Status nieobsługiwany.";
}
