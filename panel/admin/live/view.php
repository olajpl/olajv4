<?php
// admin/live/view.php — Olaj.pl V4
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

require_once __DIR__ . '/../../layout/layout_header.php';

$owner_id    = $_SESSION['user']['owner_id'] ?? 0;
$operator_id = $_SESSION['user']['id'] ?? 0;
$stream_id   = (int)($_GET['id'] ?? $_GET['live_id'] ?? 0);

// Pobranie transmisji
$stream = null;
if ($stream_id) {
  $st = $pdo->prepare("SELECT id, owner_id, title, status, started_at, ended_at FROM live_streams WHERE id=? AND owner_id=? LIMIT 1");
  $st->execute([$stream_id, $owner_id]);
  $stream = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$stream) {
  echo '<div class="max-w-3xl mx-auto p-6">Nie znaleziono transmisji.</div>';
  require_once __DIR__ . '/../../layout/layout_footer.php';
  exit;
}
?>
<style>
  /* Proste klasy bez Tailwind @apply — działa od ręki */
  .card {
    background: #fff;
    border: 1px solid #f1f5f9;
    border-radius: 16px;
    box-shadow: 0 1px 2px rgba(16, 24, 40, .04)
  }

  .btn {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    border-radius: 12px;
    padding: .5rem 1rem;
    border: 1px solid #e5e7eb;
    background: #fff;
    color: #374151
  }

  .btn-primary {
    background: #16a34a;
    border-color: #16a34a;
    color: #fff
  }

  .btn-primary:hover {
    background: #15803d
  }

  .btn-ghost {
    background: #fff
  }

  .btn-ghost:hover {
    background: #f3f4f6
  }

  .btn-soft {
    background: #f3f4f6
  }

  .btn-soft:hover {
    background: #e5e7eb
  }

  .badge {
    display: inline-flex;
    align-items: center;
    border-radius: 9999px;
    padding: .125rem .5rem;
    font-size: .75rem;
    font-weight: 600
  }

  .badge-ok {
    background: #ecfdf5;
    color: #065f46
  }

  .badge-warn {
    background: #fffbeb;
    color: #92400e
  }

  .badge-err {
    background: #fef2f2;
    color: #991b1b
  }

  .skeleton {
    animation: pulse 1.5s ease-in-out infinite;
    background: #f3f4f6;
    border-radius: 8px
  }

  @keyframes pulse {
    0% {
      opacity: 1
    }

    50% {
      opacity: .5
    }

    100% {
      opacity: 1
    }
  }

  .sticky-top {
    position: sticky;
    top: 0;
    z-index: 40;
    background: rgba(255, 255, 255, .8);
    backdrop-filter: blur(6px);
    border-bottom: 1px solid #f1f5f9
  }

  .offcanvas {
    transition: transform .25s ease
  }

  .offcanvas.hidden {
    transform: translateX(100%)
  }

  /* Select2 „bez kreski” */
  .select2-container .select2-selection--single {
    height: 40px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    padding: 0 .75rem;
    background: #fff
  }

  .select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 38px
  }

  .select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 38px;
    right: 8px
  }

  /* Komentarz – stała szerokość 100px */
  .comment-input {
    width: 100px;
  }

  /* (opcjonalnie) klient Select2, żeby nie był 20px */
  #clientRows .select2-container {
    min-width: 220px;
  }
  
</style>
<style>
  #finalizeErrors:not(.hidden){ color:#b91c1c; } /* tailwindowy "text-red-700" vibe */
<style>
  /* żeby dropdown zawsze był nad kartami/modalami */
  .select2-container { z-index: 60; }
  .select2-container--open { z-index: 70; }
</style>

<div class="sticky-top">
  <div class="max-w-[1500px] mx-auto px-4 py-3 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="text-2xl">🎥</div>
      <div>
        <div class="text-lg font-semibold leading-tight"><?= htmlspecialchars($stream['title'] ?? ('LIVE #' . $stream_id)) ?></div>
        <div class="text-xs text-gray-500">ID: <?= (int)$stream_id ?> •
          <?php if (($stream['status'] ?? '') === 'live'): ?>
            <span class="badge badge-err">LIVE</span>
          <?php elseif (($stream['status'] ?? '') === 'planned'): ?>
            <span class="badge badge-warn">Planned</span>
          <?php else: ?>
            <span class="badge">Ended</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="flex items-center gap-2">
      <button id="btnRefresh" class="btn btn-ghost" type="button" title="Odśwież (r)">↻ Odśwież</button>
      <button id="btnFilters" class="btn btn-ghost" type="button" title="Filtry (f)">🧰 Filtry</button>
      <div class="relative">
        <input id="globalSearch" placeholder="Szukaj (Ctrl+/)" class="border rounded-xl px-3 py-2 w-64 focus:outline-none focus:ring focus:ring-emerald-200" />
      </div>
      <button id="btnFinalize" class="btn btn-primary" type="button">Wyślij podsumowania</button>
    </div>
  </div>
</div>

<main class="w-full mx-auto px-4 py-6 grid grid-cols-1 xl:grid-cols-[minmax(350px,1fr)_390px] xl:grid-rows-[auto_1fr] gap-6">
  <!-- Lewa kolumna -->
<section id="addPanel" class="space-y-6 flex-grow">
  <div class="card p-4">
    <!-- Zakładki -->
    <div class="flex items-center justify-between mb-3">
      <div class="inline-flex rounded-xl border border-slate-200 overflow-hidden">
        <button type="button" id="tabBtnAdd" class="px-4 py-2 text-sm font-medium bg-slate-100">
          ➕ Dodaj produkt
        </button>
        <button type="button" id="tabBtnPresent" class="px-4 py-2 text-sm font-medium bg-white hover:bg-slate-50 border-l border-slate-200">
          🎤 Prezentacja
        </button>
      </div>
    </div>

   
      <!-- JEDYNA FORMA -->
    <form id="addProductForm" class="space-y-4">
      <input type="hidden" name="live_id" value="<?= (int)$stream_id ?>">
<input type="hidden" name="owner_id" value="<?= (int)$_SESSION['user']['owner_id'] ?>">

     
      <input type="hidden" name="operator_user_id" value="<?= (int)$operator_id ?>">

      <!-- TAB: DODAJ -->
      <div id="tab-add" class="space-y-4">
        <!-- A) Produkt (katalog/custom) -->
        <div class="space-y-2">
          <label class="block text-sm font-medium">Produkt (katalog)</label>
          <select id="product_search" name="product_id" class="w-60 select2-product">
            <option value=""></option>
          </select>
          <div class="text-xs text-gray-500 flex items-center gap-2">
            <label class="inline-flex items-center gap-2">
              <input type="checkbox" id="toggleCustom" /> Produkt niestandardowy
            </label>
            <span id="availabilityBadge" class="badge badge-warn hidden">Sprawdzam dostępność…</span>
          </div>
        </div>

        <!-- Pola niestandardowe -->
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
      </div>

<!-- TAB: PREZENTACJA -->
<div id="tab-present" class="hidden space-y-4">
  <h2 class="text-xl font-semibold flex items-center gap-2">
    🎤 Prezentacja
    <span class="text-sm font-normal text-slate-500">— przygotuj dane dla widzów i opublikuj na sklep</span>
  </h2>

  <!-- 🔍 Wyszukiwarka produktu do prezentacji (Select2) -->
  <div class="space-y-2">
    <label class="block text-sm font-medium text-slate-700">Wyszukaj produkt do prezentacji</label>
	<!-- Miniatura produktu -->
<img id="presImage" src="" alt="" class="hidden w-24 h-24 object-contain rounded-lg border bg-white" />

<select id="present_search" class="w-full"></select>
<input type="hidden" id="present_product_id">
    <p class="text-xs text-slate-500">
      Skaner EAN/12NC działa „od strzału”, możesz też szukać po CODE/SKU/nazwie.
    </p>
  </div>

  <!-- 🏷️ Nazwa + Cena + Kod/Stan -->
  <div class="grid grid-cols-12 gap-3 items-end">
    <!-- Nazwa (węższa, nie full width) -->
    <div class="col-span-12 md:col-span-7">
      <label class="block text-sm font-medium text-slate-700">Nazwa produktu</label>
      <input
        type="text" id="presTitle"
        class="mt-1 block w-full border border-slate-300 rounded-lg px-3 py-2"
        placeholder="Np. GALLUS Black żel 3,2L — hit na czarne" />
    </div>

    <!-- Cena brutto (edytowalna na potrzeby prezentacji) -->
    <div class="col-span-6 md:col-span-3">
      <label class="block text-sm font-medium text-slate-700">Cena brutto (PLN)</label>
      <input
        type="number" step="0.01" min="0" id="presPrice"
        class="mt-1 block w-full border border-slate-300 rounded-lg px-3 py-2 text-right"
        placeholder="29.99" />
      <p class="mt-1 text-[11px] text-slate-500">Ta cena dotyczy tylko tej prezentacji.</p>
    </div>

    <!-- Kod i stan (readonly) -->
    <div class="col-span-6 md:col-span-2">
      <label class="block text-sm font-medium text-slate-700">Kod / Stan</label>
      <div class="mt-1 grid grid-cols-2 gap-2">
        <div class="text-xs text-slate-500">
          <div class="border bg-slate-50 rounded-lg px-2 py-2">
            <div class="text-[10px] uppercase">Kod</div>
            <div id="presCode" class="font-medium text-slate-700 truncate">—</div>
          </div>
        </div>
        <div class="text-xs text-slate-500">
          <div class="border bg-slate-50 rounded-lg px-2 py-2">
            <div class="text-[10px] uppercase">Stan</div>
            <div id="presStock" class="font-medium text-slate-700">—</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 🗣️ Krótki opis (pitch) -->
  <div>
    <label class="block text-sm font-medium text-slate-700">Krótki opis (pitch)</label>
    <textarea
      id="presPitch" rows="3"
      class="mt-1 block w-full border border-slate-300 rounded-lg px-3 py-2"
      placeholder="Skoncentrowany żel do czarnych tkanin, 80 prań."></textarea>
  </div>

  <!-- • Lista cech (bullets) -->
  <div>
    <div class="flex items-center justify-between">
      <label class="block text-sm font-medium text-slate-700">Cechy produktu (bullet points)</label>
      <button type="button" id="btnAddBullet" class="text-xs px-2 py-1 rounded border hover:bg-slate-50">
        + dodaj punkt
      </button>
    </div>
    <div id="presBullets" class="mt-2 space-y-2">
      <input type="text" class="w-full border border-slate-300 rounded-lg px-3 py-2" placeholder="Np. 80 prań" />
      <input type="text" class="w-full border border-slate-300 rounded-lg px-3 py-2" placeholder="Np. Skuteczny w niskich temp." />
      <input type="text" class="w-full border border-slate-300 rounded-lg px-3 py-2" placeholder="Np. Wydajna butla 3,2L" />
    </div>
  </div>

  <!-- ⚙️ Przyciski akcji -->
  <div class="flex flex-wrap gap-3 items-center">
    <a id="btnOpenPublic" href="" target="_blank"
       class="px-4 py-2 bg-slate-100 text-slate-800 rounded-lg hover:bg-slate-200">
      🌐 Podgląd publiczny
    </a>

    <button type="button" id="btnBroadcast"
            class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
      📡 Opublikuj na sklep
    </button>

    <button type="button" id="btnCopyScript"
            class="px-4 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600">
      📋 Kopiuj skrypt
    </button>

    <button type="button" id="btnClearPres"
            class="px-4 py-2 bg-gray-300 rounded-lg hover:bg-gray-400">
      🧹 Wyczyść
    </button>
  </div>

  <!-- Tościk pod akcje prezentacji -->
  <p id="copyToast" class="hidden text-xs text-emerald-600">Skopiowano do schowka ✅</p>
</div>


        <!-- B) Klienci -->
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <label class="block text-sm font-medium">Klienci</label>
            <div class="text-xs text-gray-500">Możesz dodać wielu naraz</div>
          </div>
          <div id="clientRows" class="space-y-2"></div>
          <button id="btnAddClientRow" type="button" class="btn btn-soft w-full">+ Dodaj klienta</button>
        </div>

        <!-- C) Podsumowanie + akcje -->
        <div class="flex items-center justify-between pt-2">
          <div class="text-sm text-gray-600">
            Razem: <span id="sumClients">0</span> klient(ów) × <span id="sumQty">0</span> szt = <span id="sumUnits">0</span> szt
          </div>
          <div class="flex items-center gap-2">
            <button type="button" id="btnClearForm" class="btn btn-ghost">Wyczyść</button>
            <button type="submit" class="btn btn-primary">Dodaj</button>
          </div>
        </div>
      </form>
    </div>
    <div class="card p-4">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-semibold">👥 Klienci z przypisanymi produktami</h2>
        <div class="flex items-center gap-2 text-sm">
          <select id="fltStatus" class="border rounded-xl px-2 py-1">
            <option value="">Status: wszystkie</option>
            <option value="nowe">nowe</option>
            <option value="otwarta_paczka">otwarta_paczka*</option>
          </select>
          <select id="fltSource" class="border rounded-xl px-2 py-1">
            <option value="">Źródło: wszystkie</option>
            <option value="catalog">katalog</option>
            <option value="custom">custom</option>
          </select>
          <select id="fltRes" class="border rounded-xl px-2 py-1">
            <option value="">Rezerwacje: wszystkie</option>
            <option value="reserved">reserved</option>
            <option value="committed">committed</option>
            <option value="released">released</option>
          </select>
        </div>
      </div>
      <div id="clientAccordion" class="space-y-3">
        <div class="skeleton" style="height:64px"></div>
        <div class="skeleton" style="height:64px"></div>
        <div class="skeleton" style="height:64px"></div>
      </div>
    </div>

 
  </section>

  <!-- Prawa kolumna -->
<section id="assignedList" class="space-y-6">


<aside id="commentsCard"
  class="card p-4 w-[390px] sticky top-[84px] self-start h-[calc(100vh-100px)] overflow-auto">
  <h2 class="font-semibold mb-3">💬 Komentarze LIVE</h2>
  <div id="commentList" class="space-y-3 text-sm text-gray-700">
    <div class="text-gray-400">(W przygotowaniu) — placeholder i hook JS.</div>
  </div>
  <div class="mt-3 flex items-center gap-2">
    <input id="commentInput" class="border rounded-xl px-3 py-2 flex-1" placeholder="Dodaj komentarz (Enter)" />
    <button id="btnAddComment" class="btn btn-soft" type="button">Wyślij</button>
  </div>
</aside>
  </section>
</main>

<!-- Offcanvas filtry -->
<aside id="filtersOffcanvas" class="offcanvas fixed top-0 right-0 w-[360px] h-full bg-white border-l border-gray-200 p-4 hidden">
  <div class="flex items-center justify-between mb-2">
    <h3 class="font-semibold">🧰 Filtry</h3>
    <button id="btnCloseFilters" class="btn btn-ghost">✖</button>
  </div>
  <div class="space-y-4 text-sm">
    <div>
      <label class="block text-xs text-gray-500">Zakres dat</label>
      <input id="fltDateFrom" type="datetime-local" class="w-full border rounded-xl px-2 py-1" />
      <input id="fltDateTo" type="datetime-local" class="w-full border rounded-xl px-2 py-1 mt-1" />
    </div>
    <div>
      <label class="block text-xs text-gray-500">Braki magazynowe</label>
      <select id="fltOos" class="w-full border rounded-xl px-2 py-1">
        <option value="">Wszystko</option>
        <option value="1">Tylko z brakami</option>
        <option value="0">Tylko dostępne</option>
      </select>
    </div>
    <button id="btnApplyFilters" class="btn btn-primary w-full" type="button">Zastosuj</button>
  </div>
</aside>

<!-- Modal finalizacji -->
<div id="finalizeModal" class="fixed inset-0 bg-black/30 hidden items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-4">
    <h3 class="font-semibold text-lg">Finalizować wszystkie pozycje?</h3>
    <p class="text-sm text-gray-600 mt-1">Operacja jest <strong>all-or-nothing</strong>. Jeśli chociaż jedna pozycja nie przejdzie, całość zostanie cofnięta.</p>
    <div id="finalizeErrors" class="hidden mt-2 text-sm text-rose-600"></div>
    <div class="mt-4 flex items-center justify-end gap-2">
      <button id="btnCancelFinalize" class="btn btn-ghost" type="button">Anuluj</button>
      <button id="btnConfirmFinalize" class="btn btn-primary" type="button">Tak, finalizuj</button>
    </div>
  </div>
</div>
<script>
window.OLAJ_LIVE_CFG = {
  liveId: <?= (int)$stream_id ?>,
  ownerId: <?= (int)($_SESSION['user']['owner_id'] ?? 0) ?>,
  operatorId: <?= (int)($_SESSION['user']['id'] ?? 0) ?>,
  endpoints: {
    add: 'ajax/ajax_add_live_product.php',
    list: 'ajax/ajax_live_temp_list.php',
    del: 'ajax/ajax_delete_live_product.php',
    finalize: 'ajax/ajax_finalize_batch.php',
    availability: 'ajax/ajax_check_availability.php',
    updateQty: 'ajax/ajax_update_live_qty.php',
    stats: 'ajax/ajax_live_quick_stats.php',
    presentPrefill: 'ajax/ajax_presenter_prefill.php',
    presentBroadcast: 'ajax/ajax_presenter_broadcast.php',
    /** ⬇⬇⬇ DODAJ TO: */
    presentSearch: 'ajax/ajax_presenter_search.php'
  }
};
</script>

<!-- Toasty -->
<div id="toastWrap" class="fixed top-4 right-4 space-y-2 z-50"></div>

<!-- Biblioteki: jQuery + Select2 ZANIM live.js (z CDNem jako pewniak) -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.full.min.js"></script>




<!-- Twój skrypt modułu -->
<script src="js/live.js?v=1.0.1"></script>

<!-- Guard: poczekaj aż Select2 będzie gotowy (gdyby CDN zlagował) -->
<script>
  (function() {
    function whenSelect2Ready(cb) {
      if (window.jQuery && jQuery.fn && jQuery.fn.select2) return cb();
      const t = setInterval(() => {
        if (window.jQuery && jQuery.fn && jQuery.fn.select2) {
          clearInterval(t);
          cb();
        }
      }, 100);
      setTimeout(() => clearInterval(t), 5000);
    }
    document.addEventListener('DOMContentLoaded', function() {
      whenSelect2Ready(function() {
        // Jeżeli live.js nie zrobił startu na DOMContentLoaded, można by tu awaryjnie zainicjalizować,
        // ale wg naszego live.js nie trzeba — zostawiamy jako guard.
      });
    });
  })();
</script>
<script>
(function(){
  const CFG = window.OLAJ_LIVE_CFG || (window.OLAJ_LIVE_CFG = {});
  // ✅ fallback endpointu – to właśnie brakowało
  CFG.endpoints = CFG.endpoints || {};
  if (!CFG.endpoints.presentSearch) {
    CFG.endpoints.presentSearch = 'ajax/ajax_presenter_search.php';
  }

  const qs = (s, r=document)=>r.querySelector(s);

  const addBtn  = document.getElementById('tabBtnAdd');
  const presBtn = document.getElementById('tabBtnPresent');
  const tabAdd  = document.getElementById('tab-add');
  const tabPres = document.getElementById('tab-present');

  function setTab(name){
    const onAdd = (name === 'add');
    tabAdd?.classList.toggle('hidden', !onAdd);
    tabPres?.classList.toggle('hidden', onAdd);

    // kosmetyka
    addBtn?.classList.toggle('bg-slate-100', onAdd);
    addBtn?.classList.toggle('bg-white', !onAdd);
    presBtn?.classList.toggle('bg-slate-100', !onAdd);
    presBtn?.classList.toggle('bg-white', onAdd);

    localStorage.setItem('liveTab', name);
    if (!onAdd) whenSelect2Ready(initPresenterTab);
  }

  addBtn?.addEventListener('click', ()=> setTab('add'));
  presBtn?.addEventListener('click', ()=> setTab('present'));
  setTab(localStorage.getItem('liveTab') || 'add');

  // ——— czekamy aż jQuery + Select2 będą dostępne ———
  function whenSelect2Ready(cb){
    if (window.jQuery && jQuery.fn && jQuery.fn.select2) return cb();
    const t = setInterval(()=>{
      if (window.jQuery && jQuery.fn && jQuery.fn.select2){
        clearInterval(t);
        cb();
      }
    }, 100);
    // awaryjnie porzuć po 5s
    setTimeout(()=>clearInterval(t), 5000);
  }

  let presenterInitDone = false;
  function initPresenterTab(){
    if (presenterInitDone) return;
    if (!window.jQuery || !jQuery.fn || !jQuery.fn.select2) return;

    const $ = window.jQuery;
    const $sel = $('#present_search');
    if (!$sel.length) { presenterInitDone = true; return; }

    // jeśli Select2 już siedzi – zniszcz i postaw świeżo
    try { $sel.select2('destroy'); } catch(_) {}

    $sel.select2({
      ajax: {
        url: CFG.endpoints.presentSearch,            // ✅ już nie będzie undefined
        dataType: 'json',
        delay: 200,
        data: params => ({ q: (params.term||'').trim(), owner_id: CFG.ownerId }),
        processResults: data => ({
          results: Array.isArray(data) ? data : (data?.results || [])
        })
      },
      placeholder: 'Zeskanuj EAN / wpisz CODE / 12NC / nazwę…',
      minimumInputLength: 1,
      width: '100%',
      allowClear: true,
      // ważne, żeby nie renderować dropdownu w ukrytym kontenerze
      dropdownParent: $(document.body),
      templateResult: function(item){
        if (!item.id) return item.text;
        const safeText = (item.text||'').toString();
        const img = item.thumb
          ? `<img src="${item.thumb}" class="w-8 h-8 object-contain rounded border bg-white mr-2" />`
          : '';
        return $(`<div class="flex items-center">${img}<div class="text-sm">${safeText}</div></div>`);
      }
    });

    // Po wyborze – prefill + dostępność
    $sel.off('.presentFill').on('select2:select.presentFill', async (e) => {
      const pid = e.params?.data?.id;
      if (!pid) return;

      // Prefill
      try {
        const p = new URLSearchParams({ owner_id: CFG.ownerId, product_id: String(pid) });
        const res = await fetch(CFG.endpoints.presentPrefill + '?' + p.toString(), { cache: 'no-store' });
        const d = await res.json();

        if (d?.success) {
          document.getElementById('present_product_id')?.setAttribute('value', d.product_id || '');

          const img = document.getElementById('presImage');
          if (img) {
            if (d.image) { img.src = d.image; img.alt = d.title || ''; img.classList.remove('hidden'); }
            else { img.classList.add('hidden'); }
          }

          const title = document.getElementById('presTitle');
          const price = document.getElementById('presPrice');
          const code  = document.getElementById('presCode');
          const pitch = document.getElementById('presPitch');

          title && (title.value = d.title || '');
          price && (price.value = (d.price ?? ''));
          code  && (code.textContent = d.code || '—');
          pitch && (pitch.value = d.pitch || '');

          const wrap = document.getElementById('presBullets');
          if (wrap) {
            wrap.innerHTML = '';
            (d.bullets || []).slice(0,3).forEach(b=>{
              const i=document.createElement('input');
              i.type='text';
              i.className='w-full border border-slate-300 rounded-lg px-3 py-2';
              i.value=b||'';
              wrap.appendChild(i);
            });
            if (!wrap.children.length) {
              ['Np. 80 prań','Np. Skuteczny w niskich temp.','Np. Wydajna butla 3,2L'].forEach(ph=>{
                const i=document.createElement('input');
                i.type='text';
                i.className='w-full border border-slate-300 rounded-lg px-3 py-2';
                i.placeholder=ph;
                wrap.appendChild(i);
              });
            }
          }
        }
      } catch(_) {}

      // Dostępność
      try {
        const q = new URLSearchParams({ owner_id: CFG.ownerId, live_id: CFG.liveId, product_id: String(pid) });
        const r = await fetch(CFG.endpoints.availability + '?' + q.toString(), { cache: 'no-store' });
        const a = await r.json();
        const stockEl = document.getElementById('presStock');
        if (stockEl) stockEl.textContent = a?.ok ? (a.available ?? '—') : '—';
      } catch(_) {}
    });

    // Dodawanie bulletów
    document.getElementById('btnAddBullet')?.addEventListener('click', ()=>{
      const wrap = document.getElementById('presBullets');
      const i = document.createElement('input');
      i.type='text';
      i.className='w-full border border-slate-300 rounded-lg px-3 py-2';
      i.placeholder='• kolejna cecha…';
      wrap?.appendChild(i);
    });

    presenterInitDone = true;
  }
})();
</script>


<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>