<?php
// admin/products/bulk_add.php — V4 engines: ProductEngine + StockEngine (UI)
// Czytelne jak wół na lodzie. 💪
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';
require_once __DIR__ . '/../../layout/top_panel.php';
require_once __DIR__ . '/../../layout/layout_header.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf     = $_SESSION['csrf_token'];
$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);

/** Kategorie (opcjonalnie) */
$has_categories = false; $categories=[];
try {
  $st = $pdo->prepare("SHOW TABLES LIKE 'categories'"); $st->execute();
  if ($st->rowCount() > 0) {
    $has_categories = true;
    $stmt = $pdo->prepare("SELECT id, name FROM categories WHERE owner_id = ? ORDER BY name ASC");
    $stmt->execute([$owner_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch(Throwable $e) {
  if(function_exists('logg')) logg('warning','products.bulk_add','categories fetch fail',['err'=>$e->getMessage()]);
}

/** FLASH */
$flashMsg   = $_GET['msg']   ?? '';
$flashIns   = (int)($_GET['ins']  ?? 0);
$flashUpd   = (int)($_GET['upd']  ?? 0);
$flashSkip  = (int)($_GET['skip'] ?? 0);
$flashReason= $_GET['reason'] ?? '';
$reasons = [
  'not_post'=>'Żądanie nie było POST.',
  'csrf_mismatch'=>'Token CSRF nie pasuje (odśwież stronę).',
  'no_rows'=>'Formularz nie zawierał żadnych wierszy.',
  'no_owner'=>'Brak owner_id.',
  'no_code_column'=>'Brak kolumny code/sku w products.',
  'exception'=>'Wystąpił nieoczekiwany błąd po stronie serwera.',
  'post_max_exceeded'=>'Przekroczono post_max_size — zmniejsz upload lub zwiększ limit.',
];
?>
<style>
  .kpi-wrap{display:flex;flex-wrap:wrap;gap:.5rem;margin:.5rem 0 1rem 0}
  .kpi{display:flex;align-items:center;gap:.5rem;background:#f8fafc;border:1px solid #e5e7eb;border-radius:.5rem;padding:.35rem .6rem;font-size:.9rem}
  .kpi .val{font-weight:700;color:#111827}
  .rows-scroller{overflow-x:auto}
  .rows-inner{min-width:1250px}
  .err{border-color:#ef4444 !important; box-shadow:0 0 0 1px #ef4444 inset}
  .flash{padding:.6rem .8rem;border-radius:.5rem;margin:.75rem 0; font-size:.95rem}
  .flash-ok{background:#ecfdf5;border:1px solid #10b98140;color:#065f46}
  .flash-err{background:#fef2f2;border:1px solid #ef444440;color:#7f1d1d}
  .help{font-size:.75rem;color:#6b7280}
</style>

<div class="max-w-[1600px] mx-auto px-4 py-10">
  <a href="index.php" class="inline-block mb-4 text-blue-600 hover:underline">⬅️ Wróć do produktów</a>
  <h1 class="text-2xl font-bold mb-2">📦 Masowe dodawanie / aktualizacja (ProductEngine + StockEngine)</h1>

  <?php if ($flashMsg === 'ok'): ?>
    <div class="flash flash-ok">✅ Zapis zakończony — dodano <b><?= $flashIns ?></b>, zaktualizowano <b><?= $flashUpd ?></b>, pominięto <b><?= $flashSkip ?></b>.</div>
  <?php elseif ($flashMsg === 'error'): ?>
    <div class="flash flash-err">❌ Błąd zapisu. <?= isset($reasons[$flashReason])? 'Powód: <b>'.htmlspecialchars($reasons[$flashReason]).'</b>.':'' ?> (ins: <?= $flashIns ?>, upd: <?= $flashUpd ?>, skip: <?= $flashSkip ?>)</div>
  <?php endif; ?>

  <!-- KPI -->
  <div class="kpi-wrap" id="kpiBar">
    <div class="kpi">Pozycji: <span class="val" id="kpiCount">0</span></div>
    <div class="kpi">Suma ilości: <span class="val" id="kpiQty">0</span></div>
    <div class="kpi">Suma wartości: <span class="val" id="kpiValue">0,00 zł</span></div>
    <div class="kpi">Łączna waga: <span class="val" id="kpiWeight">0,000 kg</span></div>
  </div>

  <!-- Tryby -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
    <label class="border rounded p-2 text-sm">
      <span class="block text-gray-500 mb-1">Dla istniejących produktów</span>
      <select id="existingAction" name="existing_action" form="bulkForm" class="w-full border rounded px-2 py-1">
        <option value="upsert" selected>Upsert: utwórz lub aktualizuj</option>
        <option value="update_only">Tylko aktualizuj (nowe pomiń)</option>
      </select>
    </label>
    <label class="border rounded p-2 text-sm">
      <span class="block text-gray-500 mb-1">Zmiana stanu magazynowego</span>
      <select id="stockMode" name="stock_mode" form="bulkForm" class="w-full border rounded px-2 py-1">
        <option value="set" selected>Ustaw na wartość z formularza</option>
        <option value="increment">Dodaj (przyrost) do aktualnego stanu</option>
      </select>
    </label>
    <div class="border rounded p-2 text-sm">
      <label class="inline-flex items-center gap-2">
        <input type="checkbox" id="prefillCurrentStock">
        <span>Wypełniaj „Ilość” bieżącym stanem (z wyszukiwarki)</span>
      </label>
      <div class="help mt-1">„set” = ustawia dokładnie (StockEngine policzy i zablokuje), „increment” = doda delta.</div>
    </div>
  </div>

  <!-- CSV -->
  <details class="mb-4 border rounded">
    <summary class="cursor-pointer px-3 py-2 bg-gray-50 select-none">📎 Wklej CSV / TSV</summary>
    <div class="p-3 space-y-3">
      <div class="text-sm text-gray-600">
        Separatory: <code>,</code> / <code>;</code> / tab. Obsługa cudzysłowów. Nagłówek opcjonalny (PL/EN).<br>
        Kolumny: <code>name/nazwa/title</code>, <code>code/kod/sku</code>, <code>price/cena</code>, <code>vat_rate/vat</code>, <code>stock/qty/ilosc</code>, <code>weight/waga</code>, <code>twelve_nc/12nc</code>, <code>category/kategoria</code>, <code>active/aktywny</code>.
      </div>
      <textarea id="csvInput" class="w-full border rounded p-2 font-mono" rows="8" placeholder="name;code;price;stock;weight;vat;twelve_nc;category;active&#10;Koszulka biała;T001;39.99;10;0.25;23;123456789012;T-shirty;1"></textarea>
      <div class="flex items-center gap-3">
        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" id="csvReplace"><span>Zastąp istniejące wiersze</span></label>
        <button type="button" id="csvParseBtn" class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300">➕ Wklej i dodaj</button>
      </div>
    </div>
  </details>

  <!-- Szukaj w bazie -->
  <details class="mb-4 border rounded">
    <summary class="cursor-pointer px-3 py-2 bg-gray-50 select-none">🔎 Wczytaj istniejące z bazy</summary>
    <div class="p-3 space-y-3">
      <div class="flex flex-wrap gap-2 items-end">
        <label class="text-sm">
          <span class="block text-gray-500 mb-1">Szukaj (nazwa/kod)</span>
          <input type="text" id="findQuery" class="border rounded px-2 py-1 w-[260px]" placeholder="np. T-shirt albo T00">
        </label>
        <?php if ($has_categories): ?>
        <label class="text-sm">
          <span class="block text-gray-500 mb-1">Kategoria</span>
          <select id="findCategory" class="border rounded px-2 py-1 w-[220px]">
            <option value="">Wszystkie</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>
        <button type="button" id="findBtn" class="px-3 py-2 border rounded bg-gray-100 hover:bg-gray-200">Szukaj</button>
        <button type="button" id="addSelectedBtn" class="px-3 py-2 border rounded bg-blue-600 text-white hover:bg-blue-700">➕ Dodaj zaznaczone do formularza</button>
      </div>

      <div class="overflow-auto border rounded">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="p-2"><input type="checkbox" id="selAllSearch"></th>
              <th class="p-2 text-left">Kod</th>
              <th class="p-2 text-left">Nazwa</th>
              <th class="p-2 text-right">Cena</th>
              <th class="p-2 text-right">Stan</th>
              <th class="p-2 text-right">Waga [kg]</th>
              <th class="p-2 text-right">VAT</th>
            </tr>
          </thead>
          <tbody id="searchResults"><tr><td colspan="7" class="p-3 text-gray-400">Brak wyników.</td></tr></tbody>
        </table>
      </div>
    </div>
  </details>

  <!-- Błędy -->
  <div id="formErrors" class="hidden flash flash-err"></div>

  <form id="bulkForm" method="post" action="save_bulk_add.php" enctype="multipart/form-data" class="space-y-4">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="existing_action" id="existing_action_field" value="upsert">
    <input type="hidden" name="stock_mode" id="stock_mode_field" value="set">

    <div class="rows-scroller -mx-4">
      <div class="rows-inner px-4">
        <div id="product-rows" class="space-y-3"><!-- dynamic rows --></div>
      </div>
    </div>

    <div class="flex flex-wrap gap-3 mt-4">
      <button type="button" onclick="addRow()" class="bg-gray-200 px-4 py-2 rounded">➕ Dodaj wiersz</button>
      <button type="button" onclick="addMany(5)" class="bg-gray-200 px-4 py-2 rounded">➕➕ +5</button>
      <button type="button" onclick="addMany(10)" class="bg-gray-200 px-4 py-2 rounded">➕➕ +10</button>
      <div class="flex-1"></div>
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">💾 Zapisz</button>
    </div>
  </form>
</div>

<?php if ($has_categories): ?>
<template id="category-template">
  <select class="w-full border px-2 py-1 rounded" name="">
    <option value="">– kategoria –</option>
    <?php foreach ($categories as $c): ?>
      <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
</template>
<?php endif; ?>

<!-- Modal (placeholder) -->
<div id="modal-codes" class="hidden fixed inset-0 bg-black/25 flex justify-center items-center z-50">
  <div class="bg-white p-4 rounded shadow-md max-w-lg w-full relative" id="modal-content">
    <button type="button" aria-label="Zamknij" class="absolute top-2 right-2" onclick="closeSuggestModal()">✖</button>
    <div id="suggested-content">Tu wyświetlaj sugestie kodów z własnego endpointu.</div>
  </div>
</div>

<script>
let rowCount = 0;
document.getElementById('existingAction').addEventListener('change', e=>{ document.getElementById('existing_action_field').value = e.target.value; });
document.getElementById('stockMode').addEventListener('change', e=>{ document.getElementById('stock_mode_field').value = e.target.value; });

function addMany(n=5){ for(let i=0;i<n;i++) addRow(); recomputeKPI(); }

function addRow(prefill = {}) {
  const container = document.getElementById('product-rows');
  const idx = ++rowCount;
  const lastCode = getLastCode();
  const nextCode = generateNextCode(lastCode || (prefill.code || 'P000'));

  const row = document.createElement('div');
  row.className = "grid grid-cols-12 gap-2 items-start bg-white border rounded p-2";
  row.dataset.rowId = String(idx);

  row.innerHTML = `
    <input type="hidden" name="row_id[${idx}]" value="${idx}">
    <div class="col-span-3">
      <label class="text-[11px] text-gray-500">Nazwa</label>
      <input type="text" name="name[${idx}]" class="w-full border px-2 py-[6px] rounded text-sm" required value="${escapeHtml(prefill.name || '')}">
    </div>

    <div class="col-span-2 grid grid-cols-2 gap-2">
      <div>
        <label class="text-[11px] text-gray-500">Cena</label>
        <input type="number" step="0.01" name="price[${idx}]" class="w-full border px-2 py-[6px] rounded text-sm text-right" value="${prefill.price ?? ''}">
      </div>
      <div>
        <label class="text-[11px] text-gray-500">VAT</label>
        <select name="vat_rate[${idx}]" class="w-full border px-2 py-[6px] rounded text-sm">
          ${[0,5,8,23].map(v => `<option value="${v}" ${String(prefill.vat_rate ?? 23)===String(v)?'selected':''}>${v}%</option>`).join('')}
        </select>
      </div>
    </div>

    <div class="col-span-2 grid grid-cols-2 gap-2">
      <div>
        <label class="text-[11px] text-gray-500">Ilość</label>
        <input type="number" name="stock[${idx}]" class="w-full border px-2 py-[6px] rounded text-sm text-right" value="${prefill.stock ?? ''}">
      </div>
      <div>
        <label class="text-[11px] text-gray-500">Waga [kg]</label>
        <input type="number" step="0.001" name="weight[${idx}]" class="w-full border px-2 py-[6px] rounded text-sm text-right" value="${prefill.weight ?? ''}">
      </div>
    </div>

    <div class="col-span-2 grid grid-cols-2 gap-2">
      <div>
        <label class="text-[11px] text-gray-500">12NC</label>
        <input type="text" name="twelve_nc[${idx}]" class="w-full border px-2 py-[6px] rounded text-sm" value="${escapeHtml(prefill.twelve_nc || '')}">
      </div>
      <label class="inline-flex items-center gap-2 text-sm mt-6">
        <input type="checkbox" name="active[${idx}]" value="1" ${prefill.active ? 'checked' : 'checked'}>
        <span>Aktywny</span>
      </label>
    </div>

    <div class="col-span-1">
      <?php if ($has_categories): ?>
        <label class="text-[11px] text-gray-500">Kategoria</label>
        <div id="cat-placeholder-${idx}"></div>
      <?php else: ?>
        <input type="hidden" name="category_id[${idx}]" value="">
        <div class="text-xs text-gray-500 mt-6">Brak kategorii</div>
      <?php endif; ?>
    </div>

    <div class="col-span-2 grid grid-cols-6 gap-2 items-end">
      <div class="col-span-3">
        <label class="text-[11px] text-gray-500">Kod</label>
        <input type="text" name="code[${idx}]" class="code-input w-full max-w-[160px] border px-2 py-[6px] rounded text-sm font-mono" value="${escapeHtml(prefill.code || nextCode)}" required>
      </div>
      <div class="col-span-1">
        <label class="text-[11px] text-gray-500">&nbsp;</label>
        <button type="button" onclick="openSuggestModal(this.closest('[data-row-id]').querySelector('input[name=\'code[${idx}]\']'))" class="w-full h-[34px] border rounded text-xs text-gray-600 hover:text-blue-600" title="Sugeruj kod">🔍</button>
      </div>
      <div class="col-span-2">
        <label class="text-[11px] text-gray-500">Główne zdjęcie</label>
        <input type="file" name="main_image[${idx}]" accept="image/*" class="w-full border rounded px-2 py-1 text-xs">
      </div>

      <div class="col-span-6">
        <label class="text-[11px] text-gray-500">Galeria (wiele)</label>
        <input type="file" name="gallery[${idx}][]" accept="image/*" multiple class="w-full border rounded px-2 py-1 text-xs">
      </div>

      <div class="col-span-6">
        <button type="button" onclick="removeRow(this)" class="text-red-600 hover:underline mt-1 text-sm">Usuń wiersz</button>
      </div>
    </div>
  `;

  container.appendChild(row);

  <?php if ($has_categories): ?>
  (function(){
    const tpl = document.getElementById('category-template');
    const clone = tpl.content.cloneNode(true);
    const sel = clone.querySelector('select');
    sel.name = `category_id[${idx}]`;
    const ph = document.getElementById('cat-placeholder-'+idx);
    ph.replaceWith(clone);
  })();
  <?php endif; ?>

  row.querySelectorAll('input, select').forEach(el=>{
    el.addEventListener('input', ()=> { el.classList.remove('err'); recomputeKPI(); });
    el.addEventListener('change', ()=> { el.classList.remove('err'); recomputeKPI(); });
  });
  recomputeKPI();
}
function removeRow(btn){ const row = btn.closest('[data-row-id]'); if (row) row.remove(); recomputeKPI(); }
function getLastCode(){ const codes = document.querySelectorAll('input[name^="code["]'); return codes.length? codes[codes.length-1].value.trim() : ''; }
function generateNextCode(base){ const m=String(base||'').match(/^(.*?)(\d+)$/); if(!m) return (base||'P')+'001'; return m[1]+String(parseInt(m[2],10)+1).padStart(m[2].length,'0'); }
function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }

// CSV
document.getElementById('csvParseBtn').addEventListener('click', ()=>{
  const txt = (document.getElementById('csvInput').value || '').trim();
  if (!txt) return;
  const rows = parseCSVSmart(txt);
  if (!rows.length) return;
  let header = null;
  if (rows.length && looksLikeHeader(rows[0])) header = rows.shift().map(s=>s.trim());
  const idx = buildColumnIndex(header);
  if (document.getElementById('csvReplace').checked) { document.getElementById('product-rows').innerHTML = ''; rowCount = 0; }
  rows.forEach(cells=>{
    const get = name => { const i = idx[name]; return (i!=null && i<cells.length) ? cells[i].trim() : ''; };
    const pre = {
      name: get('name'),
      code: get('code'),
      price: normalizeNum(get('price')),
      vat_rate: normalizeNum(get('vat')),
      stock: normalizeInt(get('stock')),
      weight: normalizeNum(get('weight')),
      twelve_nc: get('twelve_nc'),
      active: normalizeBool(get('active'))
    };
    addRow(pre);
  });
  recomputeKPI();
});
function parseCSVSmart(text){
  const sample=text.slice(0,2000);
  const counts={'\t':(sample.match(/\t/g)||[]).length,';':(sample.match(/;/g)||[]).length,',':(sample.match(/,/g)||[]).length};
  const delim=Object.keys(counts).sort((a,b)=>counts[b]-counts[a])[0]||',';
  return parseCSV(text,delim);
}
function parseCSV(text,delim){
  const rows=[], len=text.length; let i=0,cur='',inQ=false,row=[];
  while(i<len){ const ch=text[i];
    if(ch==='"'){ if(inQ && text[i+1]==='"'){cur+='"';i+=2;continue;} inQ=!inQ;i++;continue; }
    if(!inQ && (ch==='\n'||ch==='\r')){ if(!(row.length===0&&cur.trim()==='')){row.push(cur);rows.push(row);} cur='';row=[]; if(ch==='\r'&&text[i+1]==='\n') i++; i++; continue; }
    if(!inQ && ch===delim){ row.push(cur); cur=''; i++; continue; }
    cur+=ch; i++;
  }
  if(cur.length||row.length){ row.push(cur); rows.push(row); }
  return rows.map(r=>r.map(c=>c.replace(/^\uFEFF/,'').trim()));
}
function looksLikeHeader(arr){
  const s=arr.join(' ').toLowerCase();
  const keys=['name','nazwa','title','kod','code','sku','price','cena','qty','quantity','ilosc','ilość','vat','weight','waga','12nc','twelve','category','kategoria','active','aktywny'];
  return keys.some(k=>s.includes(k));
}
function buildColumnIndex(header){
  const find=(aliases)=>{ if(!header) return null; const low=header.map(h=>h.toLowerCase()); for(const a of aliases){ const i=low.indexOf(a); if(i!==-1) return i; } return null; };
  return {
    name:      find(['name','nazwa','title']),
    code:      find(['code','kod','sku','symbol']),
    price:     find(['price','cena','netto','brutto']),
    vat:       find(['vat','vat_rate','vat%']),
    stock:     find(['stock','ilosc','ilość','qty','quantity']),
    weight:    find(['weight','waga','mass']),
    twelve_nc: find(['12nc','twelve_nc','12 nc']),
    active:    find(['active','aktywny','status']),
  };
}
function normalizeNum(v){ if(v===''||v==null) return ''; v=String(v).replace(/\s/g,'').replace(',','.'); const n=parseFloat(v); return isFinite(n)?n:''; }
function normalizeInt(v){ if(v===''||v==null) return ''; const n=parseInt(String(v).replace(/\s/g,''),10); return isFinite(n)?n:''; }
function normalizeBool(v){ const s=String(v||'').trim().toLowerCase(); return (s==='1'||s==='true'||s==='tak'||s==='yes'||s==='y'||s==='aktywny'); }

// Szukaj
document.getElementById('findBtn').addEventListener('click', doSearch);
document.getElementById('selAllSearch').addEventListener('change', e=>{
  document.querySelectorAll('.srch-row input[type="checkbox"]').forEach(cb=>cb.checked=e.target.checked);
});
document.getElementById('addSelectedBtn').addEventListener('click', ()=>{
  const rows = [...document.querySelectorAll('.srch-row input[type="checkbox"]:checked')].map(cb=>cb.closest('.srch-row'));
  const prefillCurrent = document.getElementById('prefillCurrentStock').checked;
  rows.forEach(r=>{
    const pre = {
      code: r.dataset.code, name: r.dataset.name,
      price: parseFloat(r.dataset.price||'')||'',
      vat_rate: parseFloat(r.dataset.vat||'')||'',
      stock: prefillCurrent ? (parseInt(r.dataset.stock||'0',10)||0) : '',
      weight: parseFloat(r.dataset.weight||'')||'',
      twelve_nc: r.dataset.twelve_nc||'',
      category_id: r.dataset.category_id ? parseInt(r.dataset.category_id,10) : ''
    };
    addRow(pre);
  });
  recomputeKPI();
});
function doSearch(){
  const q   = encodeURIComponent(document.getElementById('findQuery').value||'');
  const cat = document.getElementById('findCategory') ? encodeURIComponent(document.getElementById('findCategory').value||'') : '';
  const url = `partials/search_products.php?q=${q}&category_id=${cat}`;
  const tbody = document.getElementById('searchResults');
  tbody.innerHTML = '<tr><td colspan="7" class="p-3 text-gray-500">⏳ Szukam...</td></tr>';
  fetch(url, { headers: { 'Accept': 'application/json' }})
    .then(async (r)=>{ const t=await r.text(); let d; try{d=JSON.parse(t);}catch(e){ throw new Error(`Nieprawidłowa odpowiedź (nie-JSON). Status ${r.status}`);} if (!r.ok) throw new Error(d?.message||'Błąd'); return d; })
    .then(list=>{
      tbody.innerHTML=''; if(!list||!list.length){ tbody.innerHTML='<tr><td colspan="7" class="p-3 text-gray-400">Brak wyników.</td></tr>'; return; }
      list.forEach(p=>{
        const tr=document.createElement('tr'); tr.className='srch-row border-t';
        tr.dataset.code=p.code||''; tr.dataset.name=p.name||''; tr.dataset.price=p.price||'';
        tr.dataset.vat=p.vat_rate||''; tr.dataset.stock=p.stock||''; tr.dataset.weight=p.weight||'';
        tr.dataset.twelve_nc=p.twelve_nc||''; tr.dataset.category_id=p.category_id||'';
        tr.innerHTML=`
          <td class="p-2 text-center"><input type="checkbox"></td>
          <td class="p-2 font-mono">${escapeHtml(p.code||'')}</td>
          <td class="p-2">${escapeHtml(p.name||'')}</td>
          <td class="p-2 text-right">${(p.price!=null)?Number(p.price).toFixed(2):'—'}</td>
          <td class="p-2 text-right">${(p.stock!=null)?p.stock:'—'}</td>
          <td class="p-2 text-right">${(p.weight!=null)?p.weight:'—'}</td>
          <td class="p-2 text-right">${(p.vat_rate!=null)?p.vat_rate+'%':'—'}</td>
        `;
        tbody.appendChild(tr);
      });
    })
    .catch(err=>{ tbody.innerHTML = `<tr><td colspan="7" class="p-3 text-red-600">Błąd: ${escapeHtml(err.message||'Nieznany')}</td></tr>`; });
}

// KPI
function recomputeKPI(){
  const rows = document.querySelectorAll('#product-rows [data-row-id]');
  let count=0,sumQty=0,sumValue=0,sumWeight=0;
  rows.forEach(row=>{
    const name   = (row.querySelector('input[name^="name["]')?.value || '').trim();
    const qty    = parseInt(row.querySelector('input[name^="stock["]')?.value || '0', 10) || 0;
    const price  = parseFloat(row.querySelector('input[name^="price["]')?.value || '0') || 0;
    const weight = parseFloat(row.querySelector('input[name^="weight["]')?.value || '0') || 0;
    if (name || qty>0 || price>0) count++;
    sumQty    += qty;
    sumValue  += price*qty;
    sumWeight += weight*qty;
  });
  document.getElementById('kpiCount').textContent  = count.toLocaleString('pl-PL');
  document.getElementById('kpiQty').textContent    = sumQty.toLocaleString('pl-PL');
  document.getElementById('kpiValue').textContent  = (Math.round(sumValue*100)/100).toLocaleString('pl-PL',{minimumFractionDigits:2,maximumFractionDigits:2})+' zł';
  document.getElementById('kpiWeight').textContent = (Math.round(sumWeight*1000)/1000).toLocaleString('pl-PL',{minimumFractionDigits:3,maximumFractionDigits:3})+' kg';
}
document.addEventListener('input', e=>{ if (e.target.closest('#product-rows')) recomputeKPI(); });

// Walidacja
document.getElementById('bulkForm').addEventListener('submit', (e)=>{
  const rows = document.querySelectorAll('#product-rows [data-row-id]');
  const errs=[]; let idx=0;
  document.querySelectorAll('#product-rows .err').forEach(el=>el.classList.remove('err'));
  rows.forEach(row=>{
    idx++;
    const nameEl=row.querySelector('input[name^="name["]');
    const codeEl=row.querySelector('input[name^="code["]');
    const priceEl=row.querySelector('input[name^="price["]');
    const stockEl=row.querySelector('input[name^="stock["]');
    const weightEl=row.querySelector('input[name^="weight["]');
    const name=(nameEl?.value||'').trim(), code=(codeEl?.value||'').trim();
    const price=priceEl?.value, stock=stockEl?.value, weight=weightEl?.value;
    if (!name){ errs.push(`Wiersz ${idx}: brak nazwy.`); nameEl.classList.add('err'); }
    if (!code){ errs.push(`Wiersz ${idx}: brak kodu.`); codeEl.classList.add('err'); }
    if (price!=='' && isNaN(parseFloat(price))){ errs.push(`Wiersz ${idx}: cena nie jest liczbą.`); priceEl.classList.add('err'); }
    if (stock!=='' && isNaN(parseInt(stock,10))){ errs.push(`Wiersz ${idx}: ilość nie jest całkowita.`); stockEl.classList.add('err'); }
    if (weight!=='' && isNaN(parseFloat(weight))){ errs.push(`Wiersz ${idx}: waga nie jest liczbą.`); weightEl.classList.add('err'); }
  });
  if (!rows.length) errs.push('Dodaj przynajmniej jeden wiersz.');
  if (errs.length){ e.preventDefault(); const box=document.getElementById('formErrors'); box.classList.remove('hidden'); box.innerHTML=`<strong>Popraw błędy:</strong><ul class="list-disc pl-5 mt-1">${errs.map(x=>`<li>${escapeHtml(x)}</li>`).join('')}</ul>`; box.scrollIntoView({behavior:'smooth'}); }
});

// Modal mock
function openSuggestModal(){ document.getElementById('modal-codes').classList.remove('hidden'); }
function closeSuggestModal(){ document.getElementById('modal-codes').classList.add('hidden'); }

// Autostart
window.onload = ()=>{ addRow(); recomputeKPI(); };
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
