/*
  admin/live/js/live.js — Olaj.pl V4 (stabilna)
  - jedna inicjalizacja
  - akordeony: HTML z ?format=html
  - submit "Dodaj": komplet parametrów (product/custom + client_ids[] + qty[])
*/

if (window.__OLAJ_LIVE_WIRED) {
  console.warn('[LIVE] already wired, skipping second init');
} else { window.__OLAJ_LIVE_WIRED = true; }

let autoRefreshOn = true;
let userEditing   = false;

let refreshTmr = null;
let listBusy   = false;
let listCtrl   = null;
let lastSig    = '';
let lastAt     = 0;

(function(){
  const CFG = window.OLAJ_LIVE_CFG || {};
  const qs  = (s, r=document) => r.querySelector(s);
  const qsa = (s, r=document) => Array.from(r.querySelectorAll(s));

  const toast = (msg, type='ok')=>{
    const wrap = qs('#toastWrap') || (()=>{ const d=document.createElement('div'); d.id='toastWrap'; d.className='fixed top-4 right-4 space-y-2 z-50'; document.body.appendChild(d); return d; })();
    const el=document.createElement('div');
    el.className='px-3 py-2 rounded-xl shadow border text-sm ' + (type==='err'
      ? 'bg-rose-50 border-rose-200 text-rose-800'
      : 'bg-emerald-50 border-emerald-200 text-emerald-800');
    el.textContent=msg; wrap.appendChild(el); setTimeout(()=>el.remove(), 3200);
  };

  // ---------- Select2: produkt + klient ----------
  function initSelect2Product(){
    if (!window.jQuery || !jQuery.fn?.select2) return;
    const $ = jQuery;
    const $el = $('#product_search');
    if (!$el.length) return;

    $el.prop('disabled', false).select2({
      minimumInputLength: 1,
      ajax: {
        url: 'ajax/ajax_product_search.php',
        dataType: 'json',
        delay: 200,
        data: p => ({ q: p.term, owner_id: CFG.ownerId, live_id: CFG.liveId }),
        processResults: d => ({ results: Array.isArray(d) ? d : (d?.results || []) })

      },
      placeholder: 'Wpisz nazwę lub kod…',
      width: '100%',
      allowClear: true,
      dropdownParent: $(document.body)
    }).on('select2:open', () => {
      setTimeout(() => document.querySelector('.select2-container--open .select2-search__field')?.focus(), 0);
    });
  }

  function initSelect2Client(sel){
    if (!window.jQuery || !jQuery.fn?.select2) return;
    const $ = jQuery;
    $(sel).select2({
      ajax: {
        url: 'ajax/ajax_client_search.php',
        dataType: 'json',
        delay: 200,
        data: p => ({ q: p.term || '' }),
        processResults: d => {
          const arr = Array.isArray(d) ? d : (d?.results || []);
          return { results: arr.map(c => ({ id: c.id, text: c.text || c.name })) };
        }
      },
      placeholder: 'Wybierz klienta…',
      minimumInputLength: 1,
      width: '100%',
      dropdownParent: $(document.body)
    }).on('select2:open', () => {
      document.querySelector('.select2-container--open .select2-search__field')?.focus();
    });
  }
  function initPresenterTab() {
  const $sel = $('#present_search');
  if (!$sel.data('select2')) {
    $sel.select2({
      ajax: {
        url: OLAJ_LIVE_CFG.endpoints.presentSearch,
        dataType: 'json',
        delay: 200,
        data: params => ({ q: params.term, owner_id: OLAJ_LIVE_CFG.ownerId }),
        processResults: data => ({ results: data.results || [] })
      },
      placeholder: 'Wyszukaj produkt…',
      minimumInputLength: 2,
      templateResult: formatProductResult,
      templateSelection: d => d.text || ''
    });

    $sel.on('select2:select', function(e) {
      const pid = e.params.data.id;
      $.getJSON(OLAJ_LIVE_CFG.endpoints.presentPrefill, {
        product_id: pid,
        owner_id: OLAJ_LIVE_CFG.ownerId
      }, function(res) {
        if (res.success) {
          $('#present_product_id').val(res.product_id);
          $('#presTitle').val(res.title);
          $('#presPrice').val(res.price);
          $('#presCode').text(res.code || '—');
          $('#presStock').text(res.stock ?? '—');
          $('#presPitch').val(res.pitch || '');
          $('#presBullets').empty();
          (res.bullets || []).forEach(b => {
            $('#presBullets').append(`<input type="text" class="w-full border rounded-lg px-3 py-2" value="${b}"/>`);
          });
          if (res.image) {
            $('#presentImage').attr('src', res.image).removeClass('hidden');
          } else {
            $('#presentImage').addClass('hidden');
          }
        }
      });
    });
  }
}

function formatProductResult(p) {
  if (!p.id) return p.text;
  const img = p.image ? `<img src="${p.image}" class="w-8 h-8 rounded mr-2 inline-block"/>` : '';
  return $(`<span>${img}${p.text}</span>`);
}


  // ---------- Wiersze klientów ----------
  function newClientRow(){
    const row=document.createElement('div');
    row.className='flex items-center gap-2';
    row.innerHTML = `
      <select name="client_ids[]" class="client-search flex-1"></select>
      <div class="flex items-center border rounded-xl">
        <button type="button" class="px-2" data-act="minus">−</button>
        <input type="number" name="qty[]" min="1" value="1" class="w-14 text-center outline-none" />
        <button type="button" class="px-2" data-act="plus">+</button>
      </div>
      <button type="button" class="btn btn-ghost" data-act="remove">🗑</button>
    `;
    qs('#clientRows')?.appendChild(row);
    initSelect2Client(row.querySelector('.client-search'));

    row.addEventListener('click', (ev)=>{
      const btn = ev.target.closest('[data-act]'); if (!btn) return;
      const act = btn.dataset.act; const qty=row.querySelector('input[name="qty[]"]'); const val=Number(qty.value||1);
      if (act==='plus') qty.value = val+1;
      if (act==='minus') qty.value = Math.max(1, val-1);
      if (act==='remove') row.remove();
      recalcFormSummary();
    });
    row.querySelector('input[name="qty[]"]').addEventListener('input', recalcFormSummary);
    recalcFormSummary();
    return row;
  }

  function recalcFormSummary(){
    const sumClients = qsa('#clientRows .client-search').length;
    const sumQty = qsa('#clientRows input[name="qty[]"]').map(i=>Number(i.value||0)).reduce((a,b)=>a+b,0);
    qs('#sumClients')  && (qs('#sumClients').textContent = sumClients);
    qs('#sumQty')      && (qs('#sumQty').textContent = sumQty);
    qs('#sumUnits')    && (qs('#sumUnits').textContent = sumQty);
  }

  // ---------- Toggle custom/katalog ----------
  function bindCustomToggle(){
    const cb=qs('#toggleCustom'); if (!cb) return;
    cb.addEventListener('change', (e)=>{
      const on=e.target.checked; qs('#customFields')?.classList.toggle('hidden', !on);
      if (on && window.jQuery) { jQuery('#product_search').val(null).trigger('change'); }
    });
  }

  // ---------- Formularz "Dodaj" (pewny payload) ----------
  function resetAddForm(){
    if (window.jQuery) jQuery('#product_search').val(null).trigger('change');
    const cb=qs('#toggleCustom'); if (cb) cb.checked=false; qs('#customFields')?.classList.add('hidden');
    const rows=qs('#clientRows'); if (rows){ rows.innerHTML=''; newClientRow(); }
    recalcFormSummary();
  }

  function bindAddForm(){
    const form = document.querySelector('#addProductForm');
    if (!form) return;
    if (form.dataset.bound === '1') return;
    form.dataset.bound = '1';

    // blokada scroll-wheela na ilościach
    form.addEventListener('wheel', (e)=>{ if (e.target.matches('input[name="qty[]"]')) e.preventDefault(); }, { passive:false });

    form.addEventListener('submit', async (ev)=>{
      ev.preventDefault(); ev.stopPropagation();

      const params = new URLSearchParams();
      // kontekst
      params.set('live_id', String(CFG.liveId));
      params.set('owner_id', String(CFG.ownerId));
      params.set('operator_user_id', String(CFG.operatorId));

      // produkt
      const isCustom = !!document.getElementById('toggleCustom')?.checked;
      params.set('is_custom', isCustom ? '1' : '0');
      if (isCustom) {
        params.set('name',  (qs('input[name="name"]')?.value || '').trim());
        params.set('sku',   (qs('input[name="sku"]')?.value  || '').trim());
        params.set('price', (qs('input[name="price"]')?.value|| '').trim());
        params.set('vat_rate', (qs('select[name="vat_rate"]')?.value|| '23'));
      } else {
        const pid = window.jQuery ? jQuery('#product_search').val() : null;
        if (!pid) { toast('Wybierz produkt z katalogu albo włącz tryb niestandardowy.','err'); return; }
        params.set('product_id', String(pid));
      }

      // klienci + ilości
      const ids = [], qtys = [];
      qsa('#clientRows .client-search').forEach(sel => {
        const id = window.jQuery ? jQuery(sel).val() : sel.value;
        const q  = Number(sel.closest('.flex')?.querySelector('input[name="qty[]"]')?.value || '1');
        if (id) { ids.push(id); qtys.push(Math.max(1, q)); }
      });
      if (!ids.length) { toast('Dodaj co najmniej jednego klienta.','err'); return; }
      ids.forEach(v => params.append('client_ids[]', v));
      qtys.forEach(v => params.append('qty[]', String(v)));

      try {
        const res = await fetch(CFG.endpoints.add, {
          method:'POST',
          headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
          body: params.toString(),
          cache: 'no-store'
        });
        const data = await res.json().catch(()=>null);
        if (!data?.success) throw new Error(data?.error || 'Nie udało się dodać.');

        toast('Dodano pozycję/pozycje.');
        resetAddForm();
        await fetchAndRender();
        await fetchStats();
      } catch(err){
        console.error('[LIVE:add]', err);
        toast(err.message || 'Błąd sieci przy dodawaniu.','err');
      }
    });
  }

  // ---------- Lista klientów (HTML akordeony) ----------
  async function fetchAndRender(){
    if (userEditing) return;

    const params = new URLSearchParams({
      live_id: String(CFG.liveId),
      owner_id: String(CFG.ownerId),
      status: qs('#fltStatus')?.value || '',
      source: qs('#fltSource')?.value || '',
      res:    qs('#fltRes')?.value || '',
      q:      qs('#globalSearch')?.value || '',
      format: 'html'
    });

    const sig = params.toString();
    const now = Date.now();
    if (sig === lastSig && (now - lastAt) < 800) return;
    lastSig = sig; lastAt = now;

    if (listBusy) return;
    listBusy = true;
    try { listCtrl && listCtrl.abort(); } catch(_) {}
    listCtrl = new AbortController();

    const acc = qs('#clientAccordion');
    try {
      const res = await fetch((CFG.endpoints.list || 'ajax/ajax_live_temp_list.php') + '?' + sig, {
        signal: listCtrl.signal,
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!res.ok) throw new Error('HTTP '+res.status);
      const html = await res.text();
      if (acc) {
        const prev = autoRefreshOn; autoRefreshOn = false;
        acc.innerHTML = html;
        bindListEvents();
        autoRefreshOn = prev;
      }
    } catch (e) {
      if (e.name !== 'AbortError') {
        console.warn('[LIVE] fetchAndRender failed:', e.message || e);
        autoRefreshOn = false; setTimeout(()=> autoRefreshOn = true, 5000);
      }
    } finally {
      listBusy = false;
    }
  }

  function bindListEvents(){
    const acc = qs('#clientAccordion'); if (!acc) return;
// Lazy-load przy otwarciu details
acc.querySelectorAll('details[data-client-id]').forEach(d => {
  if (d.dataset.bound) return; d.dataset.bound = '1';

  // 1) od razu po renderze – jeśli open, ładujemy
  if (d.open) {
    const host = d.querySelector('[data-client-items]');
    host && loadClientItems(d.dataset.clientId, host);
  }

  // 2) i przy każdym otwarciu
  d.addEventListener('toggle', () => {
    if (!d.open) return;
    const host = d.querySelector('[data-client-items]');
    // ładuj tylko jeśli jeszcze nie wczytane (prosty cache: data-loaded)
    if (host && !host.dataset.loaded) {
      host.dataset.loaded = '1';
      loadClientItems(d.dataset.clientId, host);
    }
  });
});

async function loadClientItems(clientId, host){
  try{
    const p = new URLSearchParams({
      owner_id: String(OLAJ_LIVE_CFG.ownerId),
      live_id:  String(OLAJ_LIVE_CFG.liveId),
      client_id: String(clientId)
    });
    const res = await fetch('ajax/ajax_live_client_items.php?' + p.toString(), { cache:'no-store' });
    const html = await res.text();
    host.innerHTML = html;

    // Re-bind akcji w środku (usuń, qty)
    bindListEvents(); // korzystamy z istniejących handlerów (data-del-id, data-qty-input)
  } catch(e){
    host.innerHTML = '<div class="text-xs text-rose-600">Nie udało się pobrać pozycji.</div>';
  }
}

    // delete przykład (jeśli masz data-del-id w HTML szczegółów)
    acc.querySelectorAll('[data-del-id]').forEach(btn => {
      if (btn.dataset.bound) return; btn.dataset.bound='1';
      btn.addEventListener('click', async ()=>{
        const id = btn.dataset.delId; if (!id) return;
        if (!confirm('Usunąć pozycję?')) return;
        try{
          const body = new URLSearchParams({ id, live_id: CFG.liveId });
          const r = await fetch(CFG.endpoints.del, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
          const d = await r.json();
          if (d?.success){ toast('Usunięto pozycję.'); await fetchAndRender(); await fetchStats(); }
          else toast(d?.error || 'Nie udało się usunąć.','err');
        } catch(_){ toast('Błąd sieci przy usuwaniu.','err'); }
      });
    });
  }

  // ---------- Statystyki ----------
  async function fetchStats(){
    if (!CFG.endpoints?.stats) return;
    try{
      const p = new URLSearchParams({ live_id: CFG.liveId, owner_id: CFG.ownerId });
      const res = await fetch(CFG.endpoints.stats + '?' + p.toString());
      if (!res.ok) throw 0; const d = await res.json();
      qs('#statItems') && (qs('#statItems').textContent = d?.items ?? '—');
      qs('#statRes')   && (qs('#statRes').textContent   = d?.reservations ?? '—');
    } catch(_){}
  }

  // ---------- Finalizacja ----------
  function bindFinalize(){
    const btn = qs('#btnFinalize');
    if (!btn) return;
    btn.addEventListener('click', async ()=>{
      if (!confirm('Finalizować wszystkie pozycje?')) return;
      try{
        const body = new URLSearchParams({ live_id: String(CFG.liveId), owner_id: String(CFG.ownerId) });
        const res = await fetch(CFG.endpoints.finalize, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
        const d = await res.json();
        if (!d?.success) throw new Error(d?.error || 'Finalizacja nie powiodła się.');
        toast('Podsumowania wysłane ✅');
        await fetchAndRender();
        await fetchStats();
      } catch(e){ toast(e.message || 'Błąd finalizacji','err'); }
    });
  }

  // ---------- Filtry/szukaj + auto-refresh ----------
  function bindFilters(){
    qs('#fltStatus')?.addEventListener('change', fetchAndRender);
    qs('#fltSource')?.addEventListener('change', fetchAndRender);
    qs('#fltRes')?.addEventListener('change', fetchAndRender);
    let t=null; qs('#globalSearch')?.addEventListener('input', ()=>{ clearTimeout(t); t=setTimeout(fetchAndRender, 200); });
    qs('#btnRefresh')?.addEventListener('click', fetchAndRender);

    if (refreshTmr) clearInterval(refreshTmr);
    if (Number(CFG.autoRefreshMs) > 0) {
      refreshTmr = setInterval(()=>{ if (!userEditing && autoRefreshOn) fetchAndRender(); }, Number(CFG.autoRefreshMs));
    }
  }

  // ---------- Force toggle akordeonów (one-open) ----------
  (function(){
    const wrap = document.getElementById('clientAccordion');
    if (!wrap) return;
    wrap.addEventListener('click', function(e){
      const sum = e.target.closest('summary'); if (!sum || !wrap.contains(sum)) return;
      e.stopImmediatePropagation(); e.preventDefault();
      const d = sum.closest('details'); if (!d) return;
      const willOpen = !d.open;
      d.open = willOpen;
      if (willOpen) wrap.querySelectorAll('details[open]').forEach(x=>{ if (x!==d) x.open=false; });
    }, true);
  })();

  // ---------- Start ----------
  document.addEventListener('DOMContentLoaded', async ()=>{
    initSelect2Product();
    bindCustomToggle();

    // pierwszy wiersz klienta
    if (qs('#clientRows') && !qs('#clientRows').children.length) newClientRow();

    bindAddForm();
    bindFinalize();
    bindFilters();

    await fetchAndRender();
    await fetchStats();
  });
  // ==============================
// 4) Obsługa zakładki PREZENTACJA
// ==============================
(function(){
  const qs = (s, r=document)=>r.querySelector(s);

  function initPresentTab(){
    const btnLoad = qs('#btnLoadPlayer');
    const btnClear = qs('#btnClearPlayer');
    const btnShowOverlay = qs('#btnOverlayCode');
    const btnHideOverlay = qs('#btnOverlayHide');

    if(btnLoad && !btnLoad.dataset.bound){
      btnLoad.dataset.bound = '1';
      btnLoad.addEventListener('click', loadPlayer);
    }
    if(btnClear && !btnClear.dataset.bound){
      btnClear.dataset.bound = '1';
      btnClear.addEventListener('click', clearPlayer);
    }
    if(btnShowOverlay && !btnShowOverlay.dataset.bound){
      btnShowOverlay.dataset.bound = '1';
      btnShowOverlay.addEventListener('click', ()=>toggleOverlay(true));
    }
    if(btnHideOverlay && !btnHideOverlay.dataset.bound){
      btnHideOverlay.dataset.bound = '1';
      btnHideOverlay.addEventListener('click', ()=>toggleOverlay(false));
    }
  }

  function loadPlayer(){
    const plat = qs('#presentPlatform')?.value || '';
    const url  = (qs('#presentUrl')?.value || '').trim();
    const host = qs('#livePlayer');
    if(!host) return;

    let iframe = '';
    if(plat==='youtube'){
      const id = url.includes('http') ? (new URL(url)).searchParams.get('v') || url.split('/').pop() : url;
      iframe = `<iframe class="w-full h-full rounded-lg" src="https://www.youtube-nocookie.com/embed/${id}?autoplay=1&mute=1" allow="autoplay; encrypted-media" allowfullscreen></iframe>`;
    } else if(plat==='facebook'){
      const enc = encodeURIComponent(url);
      iframe = `<iframe class="w-full h-full rounded-lg" src="https://www.facebook.com/plugins/video.php?href=${enc}&show_text=false&autoplay=true&mute=true" allow="autoplay; encrypted-media" allowfullscreen></iframe>`;
    } else if(plat==='tiktok'){
      iframe = `<div class="p-6 text-center text-sm text-gray-500">TikTok LIVE – placeholder</div>`;
    } else {
      iframe = `<div class="p-6 text-center text-sm text-gray-500">Wybierz platformę i wklej URL/ID</div>`;
    }
    host.innerHTML = `<div class="w-full aspect-video">${iframe}</div>`;
  }

  function clearPlayer(){
    const host = qs('#livePlayer');
    if(host) host.innerHTML = `<div class="text-sm text-gray-500">Player wyczyszczony</div>`;
  }

  function toggleOverlay(show){
    const box = qs('#presentOverlay');
    if(!box) return;
    box.classList.toggle('hidden', !show);
  }

  // hook przy przełączaniu zakładek
  document.addEventListener('DOMContentLoaded', ()=>{
    if(localStorage.getItem('live_tab')==='present'){
      initPresentTab();
    }
  });
})();

  
})();
