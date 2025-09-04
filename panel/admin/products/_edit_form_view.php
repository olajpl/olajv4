<?php
// admin/products/_edit_form_view.php
// Bogaty widok edycji produktu z insightami

// Bezpieczne domyślne
$success        = $success        ?? null;
$error          = $error          ?? null;
$product        = $product        ?? [];
$sales30        = $sales30        ?? ['rows_cnt'=>0,'qty_sum'=>0,'revenue'=>0.0];
$lastResvRows   = $lastResvRows   ?? [];
$stockMoves     = $stockMoves     ?? [];
$lastSalesRows  = $lastSalesRows  ?? [];
$categories     = $categories     ?? [];
$all_tags       = $all_tags       ?? [];
$selected_tags  = $selected_tags  ?? [];
$product_image  = $product_image  ?? null;
$csrf           = $csrf           ?? '';
$product_id     = (int)($product_id ?? ($product['id'] ?? 0));
$weightValueKg  = $weightValueKg  ?? '';
$weightHint     = $weightHint     ?? '(waga – brak kolumny)';
$free_stock     = (int)($product['free_stock']     ?? 0);
$stock_cached   = (int)($product['stock']          ?? ($product['stock_cached'] ?? 0));
$stock_reserved = (int)($product['stock_reserved'] ?? 0);

// Ujednolicenie ceny
$priceNet = (float)($product['unit_price'] ?? ($product['price'] ?? 0));
$vatRate  = (float)($product['vat_rate'] ?? 23);
$nameSafe = htmlspecialchars((string)($product['name'] ?? ''), ENT_QUOTES, 'UTF-8');

// Helpery
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function badgeStatusClass(string $s): string {
  return match($s){
    'reserved'  => 'bg-amber-100 text-amber-800',
    'committed' => 'bg-emerald-100 text-emerald-800',
    'released'  => 'bg-gray-100 text-gray-700',
    default     => 'bg-gray-100 text-gray-700'
  };
}
function humanDt(?string $v): string { return $v ? date('Y-m-d H:i', strtotime($v)) : '—'; }
?>
<div class="mx-auto max-w-7xl p-6">
  <!-- Flash -->
  <?php if ($success): ?>
    <div class="mb-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-2"><?= h($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="mb-4 rounded-xl bg-red-50 border border-red-200 text-red-800 px-4 py-2"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="flex items-start justify-between gap-4 mb-6">
    <h1 class="text-2xl font-bold">✏️ Edycja — <span class="text-indigo-600"><?= $nameSafe ?></span></h1>
    <a href="/admin/products/index.php" class="text-gray-600 hover:underline">← Lista produktów</a>
  </div>

  <!-- INSIGHTY -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <!-- Sprzedaż 30 -->
    <div class="bg-white rounded-2xl border p-4 shadow-sm">
      <div class="text-sm text-gray-500 mb-1">Sprzedaż (30 dni)</div>
      <div class="text-2xl font-semibold"><?= (int)($sales30['qty_sum'] ?? 0) ?> szt</div>
      <div class="text-sm text-gray-600">
        Przychód: <span class="font-medium"><?= number_format((float)($sales30['revenue'] ?? 0), 2, ',', ' ') ?> zł</span>
      </div>
      <?php
        $cap = max(1, $stock_cached + (int)($sales30['qty_sum'] ?? 0));
        $pct = min(100, round(((float)($sales30['qty_sum'] ?? 0) / $cap) * 100));
      ?>
      <div class="mt-3 h-2 w-full bg-gray-100 rounded-full">
        <div class="h-2 rounded-full" style="width: <?= $pct ?>%; background: linear-gradient(90deg,#6366f1,#22c55e);"></div>
      </div>
    </div>

    <!-- Rezerwacje -->
    <div class="bg-white rounded-2xl border p-4 shadow-sm">
      <div class="text-sm text-gray-500 mb-1">Rezerwacje aktywne</div>
      <?php
        $reservedActive = 0;
        foreach ($lastResvRows as $r) { /* tylko wizualka z ostatnich – prawdziwy aktywny stan licz z SQL w edit.php jeśli chcesz */
          if (($r['status'] ?? '') === 'reserved') $reservedActive += (int)round((float)($r['qty'] ?? 0));
        }
        // Jeśli masz policzone dokładnie w edit.php — podmień:
        if (isset($product['stock_reserved'])) $reservedActive = (int)$product['stock_reserved'];
        $cap2 = max(1, $stock_cached);
        $pct2 = min(100, round(($reservedActive / $cap2) * 100));
      ?>
      <div class="text-2xl font-semibold"><?= (int)$reservedActive ?> szt</div>
      <div class="text-sm text-gray-600">Ostatnie wpisy: <?= count($lastResvRows) ?></div>
      <div class="mt-3 h-2 w-full bg-gray-100 rounded-full">
        <div class="h-2 rounded-full" style="width: <?= $pct2 ?>%; background: linear-gradient(90deg,#f59e0b,#ef4444);"></div>
      </div>
    </div>

    <!-- Dostępność -->
    <div class="bg-white rounded-2xl border p-4 shadow-sm">
      <div class="text-sm text-gray-500 mb-1">Dostępność</div>
      <div class="text-2xl font-semibold <?= ($free_stock>0?'text-emerald-600':'text-red-600') ?>">
        Wolny: <?= (int)$free_stock ?> / Stan: <?= (int)$stock_cached ?>
      </div>
      <div class="text-sm text-gray-600">Zarezerwowane: <?= (int)$stock_reserved ?> szt</div>
    </div>
  </div>

  <!-- FORMULARZ -->
  <form id="productForm" method="post" action="/admin/products/update.php" enctype="multipart/form-data"
        class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$product_id ?>">

    <!-- Kolumna L: dane -->
    <div class="md:col-span-2 space-y-6">
      <div class="bg-white rounded-2xl border p-5 shadow-sm">
        <h2 class="text-lg font-semibold mb-4">💼 Podstawowe informacje</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <label class="flex flex-col">
            <span class="mb-1 font-medium">Nazwa</span>
            <input type="text" name="name" required class="p-2 border rounded"
                   value="<?= h($product['name'] ?? '') ?>">
          </label>

          <label class="flex flex-col">
            <span class="mb-1 font-medium">Kod produktu</span>
            <input type="text" name="code" class="p-2 border rounded"
                   value="<?= h($product['code'] ?? '') ?>" maxlength="64" pattern="[A-Za-z0-9._-]+">
          </label>

          <label class="flex flex-col">
            <span class="mb-1 font-medium">Cena netto (PLN)</span>
            <input type="number" name="unit_price" min="0" step="0.01" required class="p-2 border rounded"
                   value="<?= h(number_format($priceNet, 2, '.', '')) ?>">
          </label>

          <label class="flex flex-col">
            <span class="mb-1 font-medium">VAT</span>
            <?php $vat = (int)round($vatRate); ?>
            <select name="vat_rate" class="p-2 border rounded">
              <?php foreach ([0,5,8,23] as $v): ?>
                <option value="<?= $v ?>" <?= $vat===$v ? 'selected':'' ?>><?= $v ?>%</option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="flex flex-col">
            <span class="mb-1 font-medium">Dostępna ilość</span>
            <input type="number" name="stock_available" min="0" step="1" class="p-2 border rounded"
                   value="<?= (int)$stock_cached ?>">
            <span class="text-xs text-gray-500 mt-1">
              Wolny: <b class="<?= $free_stock>0?'text-emerald-600':'text-red-600' ?>"><?= (int)$free_stock ?></b>,
              Zarezerwowane: <b><?= (int)$stock_reserved ?></b>
            </span>
          </label>

          <label class="flex flex-col">
            <span class="mb-1 font-medium">Waga</span>
            <div class="flex gap-2">
              <input type="number" name="weight" step="0.001" min="0"
                     class="p-2 border rounded w-full"
                     value="<?= h($weightValueKg) ?>" placeholder="w kilogramach">
              <span class="text-sm self-center text-gray-600">kg</span>
            </div>
            <span class="text-xs text-gray-500 mt-1"><?= h($weightHint) ?></span>
          </label>

          <label class="flex items-center gap-2 mt-1">
            <input type="checkbox" name="active" value="1" <?= !empty($product['active']) ? 'checked':'' ?>>
            <span>Aktywny</span>
          </label>

          <label class="flex flex-col">
            <span class="mb-1 font-medium">Kod 12NC</span>
            <input type="text" name="twelve_nc" class="p-2 border rounded"
                   value="<?= h((string)($product['twelve_nc'] ?? '')) ?>" maxlength="64">
          </label>

          <?php if ($categories): ?>
          <label class="flex flex-col">
            <span class="mb-1 font-medium">Kategoria</span>
            <select name="category_id" class="p-2 border rounded">
              <option value="">— Wybierz —</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>"
                  <?= (!empty($product['category_id']) && (int)$product['category_id']===(int)$cat['id'])?'selected':'' ?>>
                  <?= h($cat['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php endif; ?>
        </div>

        <!-- TAGI -->
        <div class="mt-4">
          <span class="block font-medium mb-1">Tagi</span>
          <div class="flex flex-wrap gap-2">
            <?php foreach ($all_tags as $tag):
              $bg = trim((string)($tag['color'] ?? '#6b7280'));
              if (!preg_match('/^#?[0-9a-f]{3,6}$/i', $bg)) $bg = '#6b7280';
              if ($bg[0] !== '#') $bg = '#'.$bg;
            ?>
              <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="tags[]" value="<?= (int)$tag['id'] ?>"
                       <?= in_array((int)$tag['id'], $selected_tags, true) ? 'checked':'' ?>>
                <span class="px-2 py-1 rounded text-white" style="background-color: <?= h($bg) ?>">
                  <?= h($tag['name']) ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- OPIS -->
      <div class="bg-white rounded-2xl border p-5 shadow-sm">
        <h2 class="text-lg font-semibold mb-4">📝 Opis</h2>
        <div class="flex gap-2 items-start">
          <textarea name="description" id="description" class="w-full h-44 p-2 border rounded"><?= h($product['description'] ?? '') ?></textarea>
          <button type="button" id="generate-description" class="px-3 py-2 bg-indigo-600 text-white rounded text-sm hover:bg-indigo-700">🧠 Wygeneruj</button>
        </div>
        <div id="gen-status" class="text-xs text-gray-500 mt-2 hidden">Generuję opis…</div>
      </div>

      <div>
        <button type="submit" class="w-full sm:w-auto bg-green-600 text-white px-5 py-2 rounded hover:bg-green-700">📂 Zapisz zmiany</button>
      </div>
    </div>

    <!-- Kolumna P: media + listy -->
    <div class="space-y-6">
      <!-- Produkt / obraz -->
      <div class="bg-white rounded-2xl border p-5 shadow-sm">
        <h3 class="font-semibold mb-3">🖼️ Produkt</h3>
        <?php if ($product_image): ?>
          <img src="/uploads/products/<?= h($product_image) ?>" alt="" class="w-full max-w-[260px] rounded mx-auto mb-3 border">
        <?php else: ?>
          <div class="text-sm text-gray-500 mb-3">Brak zdjęcia głównego.</div>
        <?php endif; ?>
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm">
        <p class="text-xs text-gray-500 mt-2">Nowe zdjęcie stanie się główne.</p>
      </div>

      <!-- Rezerwacje -->
      <div class="bg-white rounded-2xl border p-5 shadow-sm">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-semibold">⏳ Ostatnie rezerwacje</h3>
          <a class="text-sm text-indigo-600 hover:underline"
             href="/admin/products/waitlist.php?product_id=<?= (int)$product_id ?>">pełna lista</a>
        </div>
        <?php if (!$lastResvRows): ?>
          <div class="text-sm text-gray-500">Brak danych.</div>
        <?php else: ?>
          <div class="space-y-2">
            <?php foreach ($lastResvRows as $r): ?>
              <div class="flex items-center justify-between text-sm border rounded px-2 py-1">
                <div>
                  <span class="px-2 py-0.5 rounded <?= badgeStatusClass((string)($r['status'] ?? '')) ?>">
                    <?= h((string)($r['status'] ?? '')) ?>
                  </span>
                  <span class="ml-2">qty: <b><?= (int)round((float)($r['qty'] ?? 0)) ?></b></span>
                  <?php if (!empty($r['source_type'])): ?>
                    <span class="ml-2 text-gray-600">
                      <?= h((string)$r['source_type']) ?><?= !empty($r['live_id'])? ' #'.(int)$r['live_id'] : '' ?>
                    </span>
                  <?php endif; ?>
                </div>
                <div class="text-gray-500"><?= humanDt($r['reserved_at'] ?? null) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Historia stanów -->
      <div class="bg-white rounded-2xl border p-5 shadow-sm">
        <h3 class="font-semibold mb-3">📦 Historia stanów</h3>
        <?php if (!$stockMoves): ?>
          <div class="flex gap-2">
  <input id="quick-delta" type="number" step="1" class="p-2 border rounded w-24" placeholder="+/-">
  <button class="px-3 py-2 bg-slate-700 text-white rounded" onclick="quickAdjust(+1)">+1</button>
  <button class="px-3 py-2 bg-slate-700 text-white rounded" onclick="quickAdjust(-1)">-1</button>
  <button class="px-3 py-2 bg-indigo-600 text-white rounded" onclick="quickApply()">Zastosuj</button>
</div>
<script>
async function _moveStock(delta, note='Korekta szybka'){
  const res = await fetch('/admin/products/api/stock_move.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    credentials:'include',
    body: JSON.stringify({ product_id: <?= (int)$product_id ?>, delta, note })
  });
  const j = await res.json();
  if (!j.ok) throw new Error(j.error||'move_failed');
}
function quickAdjust(v){ const i=document.getElementById('quick-delta'); i.value = (parseInt(i.value||'0',10)+v); }
async function quickApply(){
  const v = parseInt(document.getElementById('quick-delta').value||'0',10);
  if(!v) return;
  try { await _moveStock(v); location.reload(); } catch(e){ alert('❌ '+e.message); }
}
</script>

        <?php else: ?>
          <div class="space-y-2 text-sm">
            <?php foreach ($stockMoves as $m):
              $type = (string)($m['movement_type'] ?? '');
              $qRaw = (float)($m['qty'] ?? 0);
              $sign = ''; $cls = 'text-gray-700'; $qOut = $qRaw;
              // DB: 'in','out','adjust','return'
              if ($type === 'out') { $sign = '-'; $cls = 'text-red-600'; $qOut = abs($qRaw); }
              elseif ($type === 'in' || $type === 'return') { $sign = '+'; $cls = 'text-emerald-600'; $qOut = abs($qRaw); }
              elseif ($type === 'adjust') {
                if ($qRaw < 0) { $cls = 'text-red-600'; $qOut = abs($qRaw); $sign='-'; }
                elseif ($qRaw > 0) { $cls = 'text-emerald-600'; $sign = '+'; }
                else { $cls='text-gray-600'; $sign='±'; }
              }
              $why = trim((string)($m['note'] ?? ''));
              if ($why === '') {
                $why = match($type){ 'in'=>'Przyjęcie','out'=>'Wydanie','return'=>'Zwrot','adjust'=>'Korekta', default=>ucfirst($type) };
              }
            ?>
              <div class="flex justify-between border rounded px-2 py-1">
                <div class="flex flex-col">
                  <div class="flex items-center gap-2">
                    <span class="<?= $cls ?> font-medium"><?= $sign . number_format($qOut, 0, ',', ' ') ?></span>
                    <span class="text-gray-700"><?= h($why) ?></span>
                  </div>
                </div>
                <div class="text-gray-400"><?= humanDt($m['created_at'] ?? null) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Kalkulator ceny -->
      <div class="bg-white rounded-2xl border p-5 shadow-sm">
        <h3 class="font-semibold mb-3">📊 Kalkulator ceny</h3>
        <div class="space-y-3 text-sm">
          <label class="flex flex-col">
            <span class="mb-1">Koszt zakupu (netto)</span>
            <input type="number" step="0.01" min="0" id="calc-cost" class="p-2 border rounded" value="">
          </label>
          <label class="flex flex-col">
            <span class="mb-1">Cena sprzedaży (netto)</span>
            <input type="number" step="0.01" min="0" id="calc-price" class="p-2 border rounded" value="<?= h(number_format($priceNet,2,'.','')) ?>">
          </label>
          <label class="flex flex-col">
            <span class="mb-1">VAT (%)</span>
            <input type="number" step="1" min="0" id="calc-vat" class="p-2 border rounded" value="<?= (int)$vatRate ?>">
          </label>
          <div class="pt-2 border-t mt-2 text-gray-700">
            <div>💰 Cena brutto: <b id="calc-brutto">0.00</b> zł</div>
            <div>📈 Marża: <b id="calc-margin">0.00</b> zł (<span id="calc-margin-pct">0</span>%)</div>
          </div>
        </div>
      </div>

      <!-- Ostatnie sprzedaże -->
      <div class="bg-white rounded-2xl border p-5 shadow-sm">
        <h3 class="font-semibold mb-3">🧾 Ostatnie sprzedaże</h3>
        <?php if (!$lastSalesRows): ?>
          <div class="text-sm text-gray-500">Brak danych.</div>
        <?php else: ?>
          <div class="space-y-2">
            <?php foreach ($lastSalesRows as $s): ?>
              <div class="flex items-center justify-between text-sm border rounded px-2 py-1">
                <div>
                  <span>Zam. #<?= (int)($s['order_id'] ?? 0) ?></span>
                  <span class="ml-2">qty: <b><?= (int)round((float)($s['qty'] ?? $s['quantity'] ?? 0)) ?></b></span>
                  <?php if (isset($s['unit_price'])): ?>
                    <span class="ml-2">cena: <?= number_format((float)$s['unit_price'],2,',',' ') ?> zł</span>
                  <?php endif; ?>
                </div>
                <div class="text-gray-500"><?= humanDt($s['o_dt'] ?? null) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </form>
</div>

<!-- Generator opisu -->
<script>
document.getElementById('generate-description')?.addEventListener('click', async () => {
  const btn  = document.getElementById('generate-description');
  const s    = document.getElementById('gen-status');
  const ta   = document.getElementById('description');
  const csrf = document.querySelector('#productForm input[name="csrf"]')?.value || '';
  btn.disabled = true; s.classList.remove('hidden');

  const payload = {
    name: document.querySelector('input[name="name"]')?.value || '',
    code: document.querySelector('input[name="code"]')?.value || '',
    twelve_nc: document.querySelector('input[name="twelve_nc"]')?.value || '',
    unit_price: document.querySelector('input[name="unit_price"]')?.value || '',
    vat_rate: document.querySelector('select[name="vat_rate"]')?.value || ''
  };
  try {
    const res = await fetch('ajax_generate_desc.php', {
      method:'POST',
      headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    });
    if (!res.ok) throw new Error('HTTP '+res.status);
    const data = await res.json();
    if (data?.description) ta.value = data.description;
  } catch(e){ alert('Nie udało się wygenerować opisu.'); }
  finally { btn.disabled = false; s.classList.add('hidden'); }
});

// Kalkulator ceny
function updateCalc(){
  const cost = parseFloat(document.getElementById('calc-cost').value) || 0;
  const price = parseFloat(document.getElementById('calc-price').value) || 0;
  const vat = parseFloat(document.getElementById('calc-vat').value) || 0;
  const brutto = price * (1 + vat/100);
  const margin = price - cost;
  const marginPct = cost > 0 ? (margin / cost * 100) : 0;
  document.getElementById('calc-brutto').textContent = brutto.toFixed(2);
  document.getElementById('calc-margin').textContent = margin.toFixed(2);
  document.getElementById('calc-margin-pct').textContent = marginPct.toFixed(1);
}
['calc-cost','calc-price','calc-vat'].forEach(id=>{
  const el = document.getElementById(id); if (el) el.addEventListener('input', updateCalc);
});
updateCalc();
</script>
