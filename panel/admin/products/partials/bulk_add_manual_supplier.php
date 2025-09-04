<?php
// partials/bulk_add_manual_supplier.php

$errors = [];
$inserted = 0;
$updated = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['products'])) {
    $pdo->beginTransaction();

    foreach ($_POST['products'] as $index => $product) {
        $name = trim($product['name'] ?? '');
        $code = trim($product['code'] ?? '');
        $purchase_price = (float) ($product['price'] ?? 0);
        $stock = (int) ($product['stock'] ?? 0);
        $vat_rate = (float) ($product['vat_rate'] ?? 23.00);
        $twelve_nc = trim($product['twelve_nc'] ?? '');
        $supplier_id = (int) ($product['supplier_id'] ?? 0);

        if ($name && $code && $supplier_id > 0) {
            $check = $pdo->prepare("SELECT id FROM products WHERE owner_id = :owner_id AND code = :code");
            $check->execute(['owner_id' => $owner_id, 'code' => $code]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $product_id = $existing['id'];

                $stmt = $pdo->prepare("UPDATE products SET name = :name, stock = stock + :stock, vat_rate = :vat_rate, twelve_nc = :twelve_nc WHERE id = :id");
                $stmt->execute([
                    'name' => $name,
                    'stock' => $stock,
                    'vat_rate' => $vat_rate,
                    'twelve_nc' => $twelve_nc,
                    'id' => $product_id
                ]);
                $updated++;
            } else {
                $stmt = $pdo->prepare("INSERT INTO products (owner_id, name, code, price, stock, vat_rate, twelve_nc) VALUES (:owner_id, :name, :code, 0, :stock, :vat_rate, :twelve_nc)");
                $stmt->execute([
                    'owner_id' => $owner_id,
                    'name' => $name,
                    'code' => $code,
                    'stock' => $stock,
                    'vat_rate' => $vat_rate,
                    'twelve_nc' => $twelve_nc
                ]);
                $product_id = $pdo->lastInsertId();
                $inserted++;
            }

            $stmt = $pdo->prepare("INSERT INTO supplier_purchases (product_id, supplier_id, purchase_price, purchase_date, quantity, owner_id) VALUES (:product_id, :supplier_id, :purchase_price, CURDATE(), :quantity, :owner_id)");
            $stmt->execute([
                'product_id' => $product_id,
                'supplier_id' => $supplier_id,
                'purchase_price' => $purchase_price,
                'quantity' => $stock,
                'owner_id' => $owner_id
            ]);
        }
    }

    $pdo->commit();

    if ($inserted > 0 || $updated > 0) {
        echo '<div class="bg-green-100 border-l-4 border-green-500 text-green-800 p-4 mb-4 rounded">';
        if ($inserted > 0) echo "Dodano $inserted nowych produktów.<br>";
        if ($updated > 0) echo "Zaktualizowano $updated istniejących produktów.";
        echo '</div>';
    }
    if ($errors) {
        echo '<div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-4 rounded">' . implode('<br>', $errors) . '</div>';
    }
}
?>

<p class="mb-2 text-sm text-gray-600">Wybierz dostawcę i uzupełnij dane zakupowe produktów. Jeśli produkt istnieje — zostanie uzupełniony. Jeśli nie — zostanie utworzony.</p>

<form method="post">
  <div id="product-list" class="space-y-2">
    <div class="grid grid-cols-7 gap-2">
      <input type="text" name="products[0][name]" class="border p-2 rounded" placeholder="Nazwa" required>
      <input type="text" name="products[0][code]" class="border p-2 rounded" placeholder="Kod" required>
      <input type="number" step="0.01" name="products[0][price]" class="border p-2 rounded" placeholder="Cena zakupu">
      <input type="number" name="products[0][stock]" class="border p-2 rounded" placeholder="Ilość">
      <input type="number" step="0.01" name="products[0][vat_rate]" class="border p-2 rounded" value="23.00">
      <input type="text" name="products[0][twelve_nc]" class="border p-2 rounded" placeholder="12nc">
      <input type="text" name="products[0][supplier_id]" class="border p-2 rounded supplier-autocomplete" placeholder="Dostawca" required>
    </div>
  </div>

  <div class="mt-4 flex gap-2">
    <button type="button" class="px-4 py-2 border rounded bg-gray-200 hover:bg-gray-300" onclick="addRow()">➕ Dodaj wiersz</button>
    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">Zapisz wszystkie</button>
  </div>
</form>

<script>
let rowIndex = 1;
function addRow() {
  const list = document.getElementById('product-list');
  const div = document.createElement('div');
  div.className = 'grid grid-cols-7 gap-2';
  div.innerHTML = `
    <input type="text" name="products[\${rowIndex}][name]" class="border p-2 rounded" placeholder="Nazwa" required>
    <input type="text" name="products[\${rowIndex}][code]" class="border p-2 rounded" placeholder="Kod" required>
    <input type="number" step="0.01" name="products[\${rowIndex}][price]" class="border p-2 rounded" placeholder="Cena zakupu">
    <input type="number" name="products[\${rowIndex}][stock]" class="border p-2 rounded" placeholder="Ilość">
    <input type="number" step="0.01" name="products[\${rowIndex}][vat_rate]" class="border p-2 rounded" value="23.00">
    <input type="text" name="products[\${rowIndex}][twelve_nc]" class="border p-2 rounded" placeholder="12nc">
    <input type="text" name="products[\${rowIndex}][supplier_id]" class="border p-2 rounded supplier-autocomplete" placeholder="Dostawca" required>
  `;
  list.appendChild(div);
  rowIndex++;
}
</script>

<script>
document.addEventListener('input', function(e) {
  if (!e.target.classList.contains('supplier-autocomplete')) return;

  const input = e.target;
  const term = input.value.trim();
  if (term.length < 2) return;

  fetch(`/api/suppliers/search.php?q=` + encodeURIComponent(term))
    .then(res => res.json())
    .then(data => {
      const list = document.createElement('ul');
      list.className = 'absolute z-10 bg-white border border-gray-300 rounded shadow-md mt-1 w-full';
      list.style.maxHeight = '150px';
      list.style.overflowY = 'auto';
      data.forEach(supplier => {
        const li = document.createElement('li');
        li.className = 'px-3 py-1 hover:bg-gray-100 cursor-pointer';
        li.textContent = supplier.name;
        li.dataset.id = supplier.id;
        li.onclick = () => {
          input.value = supplier.name;
          const hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = input.name;
          hidden.value = supplier.id;
          input.parentElement.appendChild(hidden);
          input.remove();
        };
        list.appendChild(li);
      });
      const existing = input.parentElement.querySelector('ul');
      if (existing) existing.remove();
      input.parentElement.appendChild(list);
    });
});
</script>
