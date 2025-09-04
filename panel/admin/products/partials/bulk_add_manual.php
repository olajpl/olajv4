<?php
// partials/bulk_add_manual.php
declare(strict_types=1);

if (!defined('APP_START')) define('APP_START', microtime(true));
// Załóżmy, że masz $pdo i $owner_id z include’ów auth/db.
$errors   = [];
$notices  = [];
$inserted = 0;

// (Opcjonalnie) CSRF – jeśli masz tokeny w panelu:
// if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { $errors[]='Błędny token CSRF.'; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['products']) && is_array($_POST['products'])) {
    // Twardy limit, żeby ktoś nie wysłał 10k rekordów
    $rows = array_slice($_POST['products'], 0, 500, true);

    try {
        $pdo->beginTransaction();

        // Szybkie przygotowanie statementów
        $stCheck = $pdo->prepare(
            "SELECT 1 FROM products WHERE owner_id = :owner_id AND code = :code LIMIT 1"
        );
        $stInsert = $pdo->prepare(
            "INSERT INTO products (owner_id, name, code, price, stock, weight, vat_rate)
             VALUES (:owner_id, :name, :code, :price, :stock, :weight, :vat_rate)"
        );
        // (Opcjonalnie) od razu log ruchu magazynowego – korekta startowa
        $stMove = $pdo->prepare(
            "INSERT INTO stock_movements (product_id, owner_id, movement_type, quantity, purchase_price, supplier_id, created_at)
             VALUES (:product_id, :owner_id, 'korekta', :quantity, NULL, NULL, NOW())"
        );

        foreach ($rows as $i => $product) {
            $rowNo   = $i + 1;
            $name    = trim((string)($product['name'] ?? ''));
            $code    = trim((string)($product['code'] ?? ''));
            $price   = (string)($product['price'] ?? '');
            $stock   = (string)($product['stock'] ?? '');
            $weight  = (string)($product['weight'] ?? '');
            $vatRate = (string)($product['vat_rate'] ?? '23.00');

            // Pomijamy kompletnie puste wiersze
            if ($name === '' && $code === '' && $price === '' && $stock === '' && $weight === '' && $vatRate === '') {
                continue;
            }

            // Minimalne wymagania
            if ($name === '' || $code === '') {
                $errors[] = "Wiersz #$rowNo: wymagane 'Nazwa' i 'Kod'.";
                continue;
            }

            // Normalizacja liczb (kropka jako separator)
            $priceF   = is_numeric(str_replace(',', '.', $price)) ? (float)str_replace(',', '.', $price) : 0.0;
            $weightF  = is_numeric(str_replace(',', '.', $weight)) ? (float)str_replace(',', '.', $weight) : 0.0;
            $vatF     = is_numeric(str_replace(',', '.', $vatRate)) ? (float)str_replace(',', '.', $vatRate) : 23.0;
            $stockI   = (int)$stock;

            // Walidacje
            if ($priceF < 0)  { $errors[] = "Wiersz #$rowNo: cena nie może być ujemna."; continue; }
            if ($weightF < 0) { $errors[] = "Wiersz #$rowNo: waga nie może być ujemna."; continue; }
            if ($vatF < 0)    { $errors[] = "Wiersz #$rowNo: VAT nie może być ujemny."; continue; }
            if ($stockI < 0)  { $errors[] = "Wiersz #$rowNo: ilość nie może być ujemna."; continue; }

            // Unikalność code per owner (szybki check)
            $stCheck->execute(['owner_id' => $owner_id, 'code' => $code]);
            if ($stCheck->fetchColumn()) {
                $errors[] = "Wiersz #$rowNo: kod '$code' już istnieje.";
                continue;
            }

            // Insert produktu
            $stInsert->execute([
                'owner_id' => $owner_id,
                'name'     => $name,
                'code'     => $code,
                'price'    => number_format($priceF, 2, '.', ''),
                'stock'    => $stockI,
                'weight'   => number_format($weightF, 3, '.', ''), // dokładniej dla wagi
                'vat_rate' => number_format($vatF, 2, '.', ''),
            ]);
            $productId = (int)$pdo->lastInsertId();
            $inserted++;

            // Jeśli dodajemy startowy stan > 0 – zapisz ruch magazynowy (korekta)
            if ($stockI > 0) {
                $stMove->execute([
                    'product_id' => $productId,
                    'owner_id'   => $owner_id,
                    'quantity'   => $stockI,
                ]);
            }
        }

        // Jeśli same błędy i nic nie dodano – rollback, by było „czyściej”
        if ($inserted === 0 && $errors) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Błąd bazy: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    if ($inserted > 0) {
        echo '<div class="bg-green-100 border-l-4 border-green-500 text-green-800 p-4 mb-4 rounded">Dodano ' . $inserted . ' produktów.</div>';
    }
    if ($errors) {
        echo '<div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-4 rounded">' . implode('<br>', $errors) . '</div>';
    }
}
?>

<form method="post">
  <!-- Jeśli używasz CSRF w panelu:
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES) ?>">
  -->
  <div id="product-list" class="space-y-2">
    <div class="grid grid-cols-6 gap-2">
      <input type="text" name="products[0][name]"   class="border p-2 rounded" placeholder="Nazwa" required>
      <input type="text" name="products[0][code]"   class="border p-2 rounded" placeholder="Kod" required>
      <input type="number" step="0.01" name="products[0][price]"  class="border p-2 rounded" placeholder="Cena">
      <input type="number"           name="products[0][stock]"  class="border p-2 rounded" placeholder="Ilość">
      <input type="number" step="0.001" name="products[0][weight]" class="border p-2 rounded" placeholder="Waga (kg)">
      <input type="number" step="0.01"  name="products[0][vat_rate]" class="border p-2 rounded" value="23.00" placeholder="VAT %">
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
  div.className = 'grid grid-cols-6 gap-2';
  div.innerHTML = `
    <input type="text" name="products[${rowIndex}][name]"   class="border p-2 rounded" placeholder="Nazwa" required>
    <input type="text" name="products[${rowIndex}][code]"   class="border p-2 rounded" placeholder="Kod" required>
    <input type="number" step="0.01"  name="products[${rowIndex}][price]"  class="border p-2 rounded" placeholder="Cena">
    <input type="number"              name="products[${rowIndex}][stock]"  class="border p-2 rounded" placeholder="Ilość">
    <input type="number" step="0.001" name="products[${rowIndex}][weight]" class="border p-2 rounded" placeholder="Waga (kg)">
    <input type="number" step="0.01"  name="products[${rowIndex}][vat_rate]" class="border p-2 rounded" value="23.00" placeholder="VAT %">
  `;
  list.appendChild(div);
  rowIndex++;
}
</script>
