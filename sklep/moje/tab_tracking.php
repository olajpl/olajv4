<?php
// moje/tab_tracking.php – Zakładka "Śledzenie"
$client_id = $client['id'];

$stmt = $pdo->prepare("
  SELECT l.*, o.id AS order_id, o.created_at
  FROM shipping_labels l
  JOIN orders o ON o.id = l.order_id
  WHERE o.client_id = :client_id
  ORDER BY l.created_at DESC
");
$stmt->execute(['client_id' => $client_id]);
$labels = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$labels) {
    echo "<p class='text-gray-500'>Brak etykiet lub przesyłek do śledzenia.</p>";
    return;
}
?>

<div class="space-y-4">
  <?php foreach ($labels as $label): ?>
    <div class="bg-white p-4 rounded-xl shadow">
      <div class="flex justify-between items-center border-b pb-2 mb-2">
        <div>
          <h2 class="font-semibold">Zamówienie #<?= $label['order_id'] ?></h2>
          <p class="text-sm text-gray-500"><?= date('d.m.Y H:i', strtotime($label['created_at'])) ?></p>
        </div>
        <span class="text-sm bg-gray-100 px-3 py-1 rounded-full text-gray-700">
          <?= htmlspecialchars($label['status'] ?? 'oczekuje') ?>
        </span>
      </div>

      <div class="text-sm">
        <p class="mb-1">Numer przesyłki: <strong><?= htmlspecialchars($label['tracking_number']) ?></strong></p>
        <?php if (!empty($label['label_url'])): ?>
          <a href="<?= htmlspecialchars($label['label_url']) ?>" target="_blank" class="inline-block text-blue-600 hover:underline">
            🔗 Śledź przesyłkę (<?= htmlspecialchars($label['provider']) ?>)
          </a>
        <?php else: ?>
          <p class="text-gray-400">Brak linku do przesyłki.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
