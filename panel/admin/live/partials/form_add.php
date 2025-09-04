<?php
/** expects: $stream_id */
?>
<div class="bg-white border rounded-xl p-4 shadow-sm">
  <h2 class="text-lg font-semibold mb-3">➕ Dodaj produkt</h2>

  <form id="addProductForm" class="space-y-4">
    <input type="hidden" name="live_id" value="<?= (int)$stream_id ?>">

    <!-- Produkt (Select2) -->
    <div>
      <label class="block text-sm mb-1">Produkt (katalog)</label>
      <select id="product_search" name="product_id" class="w-full"></select>
      <p id="availabilityHint" class="text-xs text-gray-500 mt-1 hidden">Sprawdzam dostępność…</p>
    </div>

    <!-- Toggle custom -->
    <div class="flex items-center gap-2">
      <input id="is_custom" name="is_custom" type="checkbox" value="1" class="h-4 w-4">
      <label for="is_custom" class="text-sm">Produkt spoza katalogu (custom)</label>
    </div>

    <!-- Pola custom -->
    <div id="customFields" class="grid grid-cols-1 md:grid-cols-4 gap-3 hidden">
      <div>
        <label class="block text-sm mb-1">Nazwa</label>
        <input type="text" name="name" class="w-full border rounded px-3 py-2" placeholder="Nazwa produktu">
      </div>
      <div>
        <label class="block text-sm mb-1">SKU</label>
        <input type="text" name="sku" class="w-full border rounded px-3 py-2" placeholder="SKU (opcjonalnie)">
      </div>
      <div>
        <label class="block text-sm mb-1">Cena netto</label>
        <input type="number" name="price" step="0.01" min="0" class="w-full border rounded px-3 py-2" placeholder="0.00">
      </div>
      <div>
        <label class="block text-sm mb-1">VAT %</label>
        <input type="number" name="vat_rate" step="1" min="0" max="99" class="w-full border rounded px-3 py-2" placeholder="23">
      </div>
    </div>

    <!-- Klienci (Select2, wielokrotny z qty per klient) -->
    <div id="clientRows" class="space-y-2">
      <div class="grid grid-cols-12 gap-3 client-row">
        <div class="col-span-9">
          <label class="block text-sm mb-1">Klient</label>
          <select name="clients[0][id]" class="client-search w-full"></select>
        </div>
        <div class="col-span-2">
          <label class="block text-sm mb-1">Ilość</label>
          <input type="number" name="clients[0][qty]" value="1" min="1" step="1" class="w-full border rounded px-3 py-2">
        </div>
        <div class="col-span-1 flex items-end">
          <button type="button" class="addRow w-full border rounded px-2 py-2 hover:bg-gray-50">➕</button>
        </div>
      </div>
    </div>

    <div class="pt-2">
      <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
        Dodaj do listy
      </button>
    </div>
  </form>
</div>
