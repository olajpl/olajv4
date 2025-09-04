<?php
// payment.php – Symulacja płatności online (np. Przelewy24, test)
session_start();
require_once __DIR__ . '/includes/db.php';

$order_id = $_GET['order_id'] ?? null;
if (!$order_id) {
    die("Brak ID zamówienia.");
}

// Pobierz zamówienie i płatność
$stmt = $pdo->prepare("SELECT p.*, c.token FROM payments p
  JOIN orders o ON o.id = p.order_id
  JOIN clients c ON o.client_id = c.id
  WHERE p.order_id = :id LIMIT 1");
$stmt->execute(['id' => $order_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    die("Nie znaleziono płatności.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Symulacja potwierdzenia płatności
    $stmt = $pdo->prepare("UPDATE payments SET status = 'opłacone', paid_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => $payment['id']]);

    header("Location: /moje.php?token=" . urlencode($payment['token']));
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Płatność online</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen py-8 px-4">
  <div class="max-w-md mx-auto bg-white p-6 rounded-xl shadow text-center">
    <h1 class="text-2xl font-bold mb-4">💳 Symulacja płatności online</h1>
    <p class="text-gray-700 mb-2">Zamówienie #<?= $payment['order_id'] ?></p>
    <p class="text-gray-700 mb-4">Kwota: <strong><?= number_format($payment['amount'], 2) ?> zł</strong></p>

    <?php if ($payment['status'] === 'opłacone'): ?>
      <p class="text-green-600 font-semibold">✅ Płatność została już opłacona.</p>
      <a href="/moje.php?token=<?= urlencode($payment['token']) ?>" class="inline-block mt-4 text-blue-600 underline">Powrót do zamówień</a>
    <?php else: ?>
      <form method="POST">
        <button type="submit" class="w-full bg-green-600 text-white py-2 px-4 rounded hover:bg-green-700">
          Zapłać teraz
        </button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
