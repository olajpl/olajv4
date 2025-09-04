<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$supplier_id = (int)($_GET['id'] ?? 0);
$owner_id    = (int)($_SESSION['user']['owner_id'] ?? 0);

if ($supplier_id <= 0 || $owner_id <= 0) {
  http_response_code(400);
  exit("Niepoprawne ID dostawcy.");
}

// Pobierz dostawcę
$stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ? AND owner_id = ?");
$stmt->execute([$supplier_id, $owner_id]);
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
  http_response_code(404);
  exit("Dostawca nie znaleziony.");
}

// Metadata → box
$meta = [];
if (!empty($supplier['metadata'])) {
  $dec = json_decode((string)$supplier['metadata'], true);
  if (is_array($dec)) $meta = $dec;
}
$box = $meta['box'] ?? '-';

$page_title = "Dostawca: " . htmlspecialchars((string)$supplier['name'], ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/../../layout/layout_header.php';
?>
<div class="p-6">
  <div class="mb-6">
    <h1 class="text-2xl font-bold mb-2">📦 <?= htmlspecialchars((string)$supplier['name'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="text-gray-600">
      📧 <?= htmlspecialchars((string)($supplier['email'] ?? 'brak'), ENT_QUOTES, 'UTF-8') ?>
      | ☎️ <?= htmlspecialchars((string)($supplier['phone'] ?? 'brak'), ENT_QUOTES, 'UTF-8') ?>
    </p>
    <p class="text-gray-600 mt-1">
      🏠 <?= htmlspecialchars((string)($supplier['address'] ?? 'brak'), ENT_QUOTES, 'UTF-8') ?>
      | 📦 Box: <?= htmlspecialchars((string)$box, ENT_QUOTES, 'UTF-8') ?>
    </p>
  </div>

  <div class="mb-6">
    <a href="index.php" class="inline-block bg-gray-200 text-sm text-gray-800 px-4 py-2 rounded hover:bg-gray-300">⬅️ Wróć do listy</a>
  </div>

  <h2 class="text-xl font-semibold mb-4">🦾 Historia zakupów</h2>
  <div class="bg-white rounded shadow p-4 overflow-x-auto">
    <table class="min-w-full text-sm table-auto">
      <thead class="bg-gray-100">
        <tr>
          <th class="px-4 py-2 text-left">Data</th>
          <th class="px-4 py-2 text-left">Produkt</th>
          <th class="px-4 py-2 text-left">Ilość</th>
          <th class="px-4 py-2 text-left">Cena netto</th>
          <th class="px-4 py-2 text-left">Notatka</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $stmt = $pdo->prepare(
          "SELECT sp.id, sp.purchased_at, sp.qty, sp.purchase_price, sp.note, p.name AS product_name
           FROM supplier_purchases sp
           JOIN products p ON p.id = sp.product_id
           WHERE sp.supplier_id = :sid AND sp.owner_id = :oid
           ORDER BY sp.purchased_at DESC
           LIMIT 200"
        );
        $stmt->execute([':sid' => $supplier_id, ':oid' => $owner_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows): ?>
          <tr>
            <td colspan="5" class="px-4 py-6 text-center text-gray-500">Brak zakupów dla tego dostawcy.</td>
          </tr>
          <?php else:
          foreach ($rows as $row): ?>
            <tr class="border-t">
              <td class="px-4 py-2"><?= htmlspecialchars((string)($row['purchased_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars((string)($row['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="px-4 py-2"><?= (int)($row['qty'] ?? 0) ?></td>
              <td class="px-4 py-2"><?= number_format((float)($row['purchase_price'] ?? 0), 2, ',', ' ') ?> zł</td>
              <td class="px-4 py-2"><?= htmlspecialchars((string)($row['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach;
        endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>