<?php
// moje/tab_orders.php – Zakładka "Zakupy"
$client_id = $client['id'];
$owner_id = $client['owner_id'];

$stmt = $pdo->prepare("SELECT * FROM orders WHERE client_id = :client_id ORDER BY created_at DESC");
$stmt->execute(['client_id' => $client_id]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$orders) {
    echo "<p class='text-gray-500'>Nie znaleziono żadnych zamówień.</p>";
    return;
}
?>

<div class="space-y-6">
<?php foreach ($orders as $order): ?>
  <div class="bg-white p-4 rounded-xl shadow">
    <div class="flex justify-between items-center border-b pb-2 mb-2">
      <h2 class="font-semibold text-lg">Zamówienie #<?= $order['id'] ?></h2>
      <span class="text-sm text-gray-500"><?= date('d.m.Y H:i', strtotime($order['created_at'])) ?></span>
    </div>

    <!-- Status zamówienia -->
    <div class="mb-3">
      <?php
        $status = $order['status'];
        $status_labels = [
          'nowe' => '🕒 Nowe',
          'otwarta_paczka' => '📦 W trakcie',
          'spakowane' => '✅ Spakowane',
          'wysłane' => '🚚 Wysłane',
          'zrealizowane' => '🎉 Zrealizowane'
        ];
        echo "<span class='inline-block text-sm px-3 py-1 rounded-full bg-gray-100 text-gray-700'>" . ($status_labels[$status] ?? $status) . "</span>";
      ?>
    </div>

    <!-- Produkty w zamówieniu -->
    <ul class="divide-y divide-gray-200">
      <?php
        $stmtItems = $pdo->prepare("
          SELECT i.*, p.name AS product_name
          FROM order_groups g
          JOIN order_items i ON i.order_group_id = g.id
          LEFT JOIN products p ON i.product_id = p.id
          WHERE g.order_id = :order_id
        ");
        $stmtItems->execute(['order_id' => $order['id']]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
      ?>

      <?php foreach ($items as $item): ?>
        <li class="py-2 flex justify-between items-center">
          <div>
            <p class="font-medium text-gray-800">
              <?= htmlspecialchars($item['product_name'] ?? $item['custom_name']) ?>
            </p>
            <p class="text-sm text-gray-500">Ilość: <?= $item['quantity'] ?> × <?= number_format($item['price'], 2) ?> zł</p>
          </div>
          <div class="text-right font-semibold">
            <?= number_format($item['quantity'] * $item['price'], 2) ?> zł
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endforeach; ?>
</div>