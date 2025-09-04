<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/layout_header.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = $_POST['name'] ?? '';
  $code = $_POST['code'] ?? '';
  $price = $_POST['price'] ?? 0;
  $stock = $_POST['stock'] ?? 0;
  $weight = $_POST['weight'] ?? 0;
  $vat_rate = $_POST['vat_rate'] ?? 23.00;
  $twelve_nc = $_POST['twelve_nc'] ?? null;

  $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE owner_id = :owner_id AND code = :code");
  $check->execute(['owner_id' => $owner_id, 'code' => $code]);
  if ($check->fetchColumn() > 0) {
    $_SESSION['error_message'] = "Kod produktu '$code' już istnieje.";
    header("Location: add.php");
    exit;
  }

  $stmt = $pdo->prepare("INSERT INTO products (owner_id, name, code, price, stock, weight, vat_rate, twelve_nc)
        VALUES (:owner_id, :name, :code, :price, :stock, :weight, :vat_rate, :twelve_nc)");
  $stmt->execute([
    'owner_id' => $owner_id,
    'name' => $name,
    'code' => $code,
    'price' => $price,
    'stock' => $stock,
    'weight' => $weight,
    'vat_rate' => $vat_rate,
    'twelve_nc' => $twelve_nc
  ]);

  $_SESSION['success_message'] = "Produkt dodany.";
  header("Location: index.php");
  exit;
}
?>

<d<!-- Modal: Add Product -->
  <div id="modal-add" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" onclick="hideAddModal()"></div>
    <div class="bg-white rounded-xl shadow-xl w-full max-w-xl mx-auto mt-24 p-4">
      <h2 class="text-lg font-semibold mb-3">➕ Dodaj produkt</h2>
      <form id="add-form" onsubmit="return submitAddProduct(event)">
        <div class="grid grid-cols-2 gap-3">
          <label class="text-sm">Nazwa
            <input name="name" class="border rounded w-full p-2" required>
          </label>
          <label class="text-sm">Kod / SKU
            <input name="code" class="border rounded w-full p-2">
          </label>
          <label class="text-sm">Cena (brutto)
            <input name="unit_price" type="number" step="0.01" min="0" class="border rounded w-full p-2" required>
          </label>
          <label class="text-sm">VAT (%)
            <input name="vat_rate" type="number" step="0.01" min="0" class="border rounded w-full p-2" value="23.00">
          </label>
          <label class="text-sm">Kategorie
            <select name="category_id" class="border rounded w-full p-2">
              <option value="">—</option>
              <?php foreach (($categories ?? []) as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="text-sm">Stan (start)
            <input name="stock_available" type="number" step="1" min="0" class="border rounded w-full p-2" value="0">
          </label>
          <label class="text-sm flex items-center gap-2 col-span-2">
            <input type="checkbox" name="active" value="1" checked> Aktywne w sklepie
          </label>
        </div>
        <div id="add-error" class="text-red-600 text-sm mt-2 hidden"></div>
        <div class="mt-4 flex justify-end gap-2">
          <button type="button" onclick="hideAddModal()" class="px-3 py-1 border rounded">Anuluj</button>
          <button type="submit" class="px-3 py-1 bg-blue-600 text-white rounded">Zapisz</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function showAddModal() {
      document.getElementById('modal-add').classList.remove('hidden');
    }

    function hideAddModal() {
      document.getElementById('modal-add').classList.add('hidden');
    }
    async function submitAddProduct(e) {
      e.preventDefault();
      const form = document.getElementById('add-form');
      const data = new FormData(form);
      const payload = Object.fromEntries(data.entries());
      payload.active = data.get('active') ? 1 : 0;

      const res = await fetch('/panel/api/products.create.php', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'fetch',
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      });
      const j = await res.json().catch(() => ({
        ok: false,
        error: 'bad_json'
      }));
      if (!j.ok) {
        const err = document.getElementById('add-error');
        err.textContent = j.error || 'Błąd zapisu';
        err.classList.remove('hidden');
        return false;
      }
      // szybka opcja: przeładuj listę (spójność)
      location.reload();
      return false;
    }
  </script>


  <?php include __DIR__ . '/../../layout/layout_footer.php'; ?>