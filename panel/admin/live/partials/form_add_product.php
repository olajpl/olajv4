<?php
// admin/live/partials/form_add_product.php
// Szkielet formularza "Dodaj produkt" dla modułu LIVE (Olaj.pl V4)
// Zakłada, że w pliku nadrzędnym masz: $stream_id, $owner_id, $operator_id
// (jeśli nie – poniższe 3 linie je dodefiniują bezpiecznie)
$owner_id    = isset($owner_id) ? (int)$owner_id : (int)($_SESSION['user']['owner_id'] ?? 0);
$operator_id = isset($operator_id) ? (int)$operator_id : (int)($_SESSION['user']['id'] ?? 0);
$stream_id   = isset($stream_id) ? (int)$stream_id : (int)($_GET['id'] ?? 0);
?>

<div class="card p-4">
  <h2 class="font-semibold mb-3">➕ Dodaj produkt</h2>

  <form id="addProductForm" class="space-y-4">
    <!-- Kontekst LIVE -->
    <input type="hidden" name="live_id" value="<?= (int)$stream_id ?>">
    <input type="hidden" name="owner_id" value="<?= (int)$owner_id ?>">
    <input type="hidden" name="operator_user_id" value="<?= (int)$operator_id ?>">

    <!-- A) Wybór produktu katalog/custom -->
    <div class="space-y-2">
      <label class="block text-sm font-medium">Produkt (katalog)</label>
      <select id="product_search" name="product_id" class="w-full"></select>

      <div class="text-xs text-gray-500 flex items-center gap-3">
        <label class="inline-flex items-center gap-2">
          <input type="checkbox" id="toggleCustom" />
          Produkt niestandardowy
        </label>

        <!-- Badge dostępności (kontrolowany przez JS: show/hide + klasy badge-*) -->
        <span id="availabilityBadge" class="badge badge-warn hidden">Sprawdzam dostępność…</span>
      </div>
    </div>

    <!-- Pola produktu custom -->
    <div id="customFields" class="grid grid-cols-2 gap-3 hidden">
      <div class="col-span-2">
        <label class="block text-sm font-medium">Nazwa</label>
        <input type="text" name="name" class="w-full border rounded-xl px-3 py-2" />
      </div>
      <div>
        <label class="block text-sm font-medium">SKU (opc.)</label>
        <input type="text" name="sku" class="w-full border rounded-xl px-3 py-2" />
      </div>
      <div>
        <label class="block text-sm font-medium">Cena (PLN)</label>
        <input type="number" step="0.01" min="0" name="price" class="w-full border rounded-xl px-3 py-2 text-right" />
      </div>
      <div>
        <label class="block text-sm font-medium">VAT</label>
        <select name="vat_rate" class="w-full border rounded-xl px-3 py-2">
          <option value="23">23%</option>
          <option value="8">8%</option>
          <option value="5">5%</option>
          <option value="0">0%</option>
        </select>
      </div>
    </div>

    <!-- B) Wybór klientów (dynamiczne wiersze dodawane przez JS) -->
    <div class="space-y-3">
      <div class="flex items-center justify-between">
        <label class="block text-sm font-medium">Klienci</label>
        <div class="text-xs text-gray-500">Możesz dodać wielu naraz</div>
      </div>

      <div id="clientRows" class="space-y-2"></div>

      <button id="btnAddClientRow" type="button" class="btn btn-soft w-full">
        + Dodaj klienta
      </button>
    </div>

    <!-- C) Podsumowanie + akcje -->
    <div class="flex items-center justify-between pt-2">
      <div class="text-sm text-gray-600">
        Razem: <span id="sumClients">0</span> klient(y) × <span id="sumQty">0</span> szt = <span id="sumUnits">0</span> szt
      </div>
      <div class="flex items-center gap-2">
        <button type="button" id="btnClearForm" class="btn btn-ghost">Wyczyść</button>
        <button type="submit" class="btn btn-primary">Dodaj</button>
      </div>
    </div>
  </form>
</div>
