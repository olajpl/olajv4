/*
  admin/live/js/live.js — Olaj.pl V4 (stabilny)
  - Endpoints w admin/live/ajax/
  - Select2 dla produktów i klientów
  - Sprawdzanie dostępności (debounce)
  - Lista klientów (HTML via AJAX), delete, qty-update (opcjonalnie)
  - Finalizacja z modalem
  - Auto-refresh + quick stats
*/

(function(){
  // =========================
  // 1) Konfiguracja i helpery
  // =========================
  const CFG = window.OLAJ_LIVE_CFG || {
    liveId: Number(document.querySelector('input[name="live_id"]')?.value || 0),
    ownerId: Number(document.querySelector('input[name="owner_id"]')?.value || 0),
    operatorId: Number(document.querySelector('input[name="operator_user_id"]')?.value || 0),
    endpoints: {
      add:         'ajax/ajax_add_live_product.php',
      list:        'ajax/ajax_live_temp_list.php',
      del:         'ajax/ajax_delete_live_product.php',
      finalize:    'ajax/ajax_finalize_batch.php',
      availability:'ajax/ajax_check_availability.php', // ⬅️ finalna ścieżka
      updateQty:   'ajax/ajax_update_live_qty.php', // jeśli masz endpoint — działa out-of-the-box
      stats:       'ajax/ajax_live_quick_stats.php'
    },
    autoRefreshMs: 5000
  };

  const qs  = (s, r=document) => r.querySelector(s);
  const qsa = (s, r=document) => Array.from(r.querySelectorAll(s));
  const money = (n)=>{ try { return new Intl.NumberFormat('pl-PL',{style:'currency',currency:'PLN'}).format(Number(n)||0); } catch { return ((Number(n)||0).toFixed(2))+' zł'; } };
  const toast = (msg, type='ok')=>{
    const wrap = qs('#toastWrap') || (()=>{ const d=document.createElement('div'); d.id='toastWrap'; d.className='fixed top-4 right-4 space-y-2 z-50'; document.body.appendChild(d); return d; })();
    const el = document.createElement('div');
    el.className = 'px-4 py-2 rounded-xl shadow border text-sm bg-white ' + (type==='err'?'border-rose-200 text-rose-700':'border-emerald-200 text-emerald-700');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(()=> el.remove(), 3500);
  };

  // =========================
  // 2) Select2 init
  // =========================
  function initSelect2Product(){
    if (typeof $ === 'undefined' || !$('#product_search').select2) return;

    $('#product_search').select2({
      minimumInputLength: 1,
      ajax: {
        url: 'ajax/ajax_product_search.php',
        dataType: 'json',
        delay: 200,
        data: p => ({ q:p.term, owner_id: (window.OLAJ_LIVE_CFG?.ownerId||0), live_id: (window.OLAJ_LIVE_CFG?.liveId||0) }),
        processResults: d => ({ results: Array.isArray(d) ? d : (d?.results || []) })
      },
      placeholder: 'Wpisz nazwę lub kod…',
      width: '100%'
    })
    .on('select2:open', ()=> setTimeout(()=> document.querySelector('.select2-search__field')?.focus(), 0))
    // mocne podpięcie eventów
    .on('select2:select select2:close change input', checkAvailabilityDebounced)
    .on('select2:clear', hideAvailability);

    // pierwszy check po starcie
    setTimeout(checkAvailabilityDebounced, 50);
  }

  function initSelect2Client(selectEl){
    if (typeof $ === 'undefined' || !$(selectEl).select2) return;
    $(selectEl).select2({
      minimumInputLength: 1,
      ajax: {
        url: 'ajax/ajax_client_search.php',
        dataType: 'json',
        delay: 200,
        data: p => ({ q:p.term, owner_id: (window.OLAJ_LIVE_CFG?.ownerId||0) }),
        processResults: d => ({ results: Array.isArray(d) ? d : (d?.results || []) })
      },
      placeholder: 'Wybierz klienta…',
      width: '100%'
    });
  }

  // =========================
  // 3) Wiersze klientów
  // =========================
  function newClientRow(){
    const row = document.createElement('div');
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
      const act = btn.dataset.act;
      const qty = row.querySelector('input[name="qty[]"]');
      const val = Number(qty.value||1);
      if (act==='plus')  qty.value = val+1;
      if (act==='minus') qty.value = Math.max(1, val-1);
      if (act==='remove') { row.remove(); }
      recalcFormSummary();
      checkAvailabilityDebounced();
    });

    // Po wpisaniu ilości: przelicz + sprawdź dostępność
    row.querySelector('input[name="qty[]"]').addEventListener('input', ()=>{
      recalcFormSummary();
      checkAvailabilityDebounced();
    });

    recalcFormSummary();
  }

  function recalcFormSummary(){
    const sumClients = qsa('#clientRows .client-search').length;
    const sumQty = qsa('#clientRows input[name="qty[]"]').map(i=>Number(i.value||0)).reduce((a,b)=>a+b,0);
    if (qs('#sumClients')) qs('#sumClients').textContent = sumClients;
    if (qs('#sumQty'))     qs('#sumQty').textContent = sumQty;
    if (qs('#sumUnits'))   qs('#sumUnits').textContent = sumQty;
  }

  // =========================
  // 4) Toggle custom/katalog
  // =========================
  function bindCustomToggle(){
    const cb = qs('#toggleCustom'); if (!cb) return;
    cb.addEventListener('change', (e)=>{
      const on = e.target.checked;
      qs('#customFields')?.classList.toggle('hidden', !on);
      if (on && typeof window.jQuery !== 'undefined') {
        jQuery('#product_search').val(null).trigger('change');
        hideAvailability();
      }
      if (!on) checkAvailabilityDebounced(); // wrócono do katalogu → sprawdź
    });
  }

  // =========================
  // 5) Dostępność (debounce)
  // =========================
  let availTmr = null;

  function showAvailability(text, kind='warn'){
    const b = qs('#availabilityBadge'); if (!b) return;
    b.textContent = text;
    b.classList.remove('hidden','badge-ok','badge-warn','badge-err');
    b.classList.add(kind==='ok'?'badge-ok':kind==='err'?'badge-err':'badge-warn');
  }

  function hideAvailability(){
    qs('#availabilityBadge')?.classList.add('hidden');
  }

  function checkAvailabilityDebounced(){
    clearTimeout(availTmr);
    availTmr = setTimeout(checkAvailability, 300);
  }

  async function checkAvailability(){
    if (qs('#toggleCustom')?.checked) {
      console.debug('[LIVE] availability: custom → skip');
      return hideAvailability();
    }
    const pid = (typeof window.jQuery !== 'undefined') ? jQuery('#product_search').val() : null;
    if (!pid) { console.debug('[LIVE] availability: no product'); return hideAvailability(); }

    showAvailability('Sprawdzam dostępność…','warn');

    // policz łączną żądaną ilość
    let requested = 0;
    document.querySelectorAll('#clientRows input[name="qty[]"]').forEach(i=>{
      const v = parseInt(i.value,10); if(!isNaN(v) && v>0) requested += v;
    });
    if (requested <= 0) requested = 1;

    try{
      const url = (window.OLAJ_LIVE_CFG?.endpoints?.availability || 'ajax/ajax_check_availability.php');
      const params = new URLSearchParams({
        product_id: String(pid),
        owner_id:   String(CFG.ownerId),
        live_id:    String(CFG.liveId),
        qty:        String(requested),
        _dbg:       '1'
      });
      const full = url + '?' + params.toString();
      console.debug('[LIVE] availability →', full);

      const res = await fetch(full, { cache:'no-store', headers:{'X-Requested-With':'XMLHttpRequest'} });
      console.debug('[LIVE] availability status', res.status);
      const data = await res.json().catch(()=> ({}));
      console.debug('[LIVE] availability payload', data);

      if (data?.ok || data?.success){
        const avail    = Number(data.available ?? data.stock ?? 0);
        const reserved = Number(data.reserved ?? 0);
        const txt = `Dostępne: ${avail} • Zarezerwowane: ${reserved}`;
        showAvailability(txt, (avail>0 ? 'ok' : 'err'));
      } else {
        showAvailability(data?.message || data?.error || 'Brak danych', 'err');
      }
    } catch (e){
      console.debug('[LIVE] availability error', e);
      showAvailability('Błąd sprawdzania', 'err');
    }
  }

  // =========================
  // 6) Formularz Dodaj produkt
  // =========================
  function resetAddForm(){
    if (typeof window.jQuery !== 'undefined') jQuery('#product_search').val(null).trigger('change');
    const cb = qs('#toggleCustom'); if (cb) cb.checked = false;
    qs('#customFields')?.classList.add('hidden');
    const rows = qs('#clientRows'); if (rows){ rows.innerHTML=''; newClientRow(); }
    hideAvailability();
    recalcFormSummary();
    // po resecie spróbujmy od razu sprawdzić dostępność (gdyby produkt był już ustawiony)
    checkAvailabilityDebounced();
  }

  function bindAddForm(){
    const form = qs('#addProductForm'); if (!form) return;

    qs('#btnAddClientRow')?.addEventListener('click', newClientRow);
    qs('#btnClearForm')?.addEventListener('click', resetAddForm);

    // blokada scroll-wheela na inputach ilości (UX)
    form.addEventListener('wheel', (e)=>{ if (e.target.matches('input[name="qty[]"]')) e.preventDefault(); }, { passive:false });

    form.addEventListener('submit', async (ev)=>{
      ev.preventDefault();

      const fd = new FormData(form);
      const params = new URLSearchParams();
      fd.forEach((v,k)=> params.append(k, v));

      // kontekst live (gdyby w HTML ktoś zapomniał hiddenów)
      params.set('live_id', String(CFG.liveId));
      params.set('owner_id', String(CFG.ownerId));
      params.set('operator_user_id', String(CFG.operatorId));

      // tryb custom/katalog
      const isCustom = !!qs('#toggleCustom')?.checked;
      params.set('is_custom', isCustom ? '1' : '0');

      try{
        const res = await fetch(CFG.endpoints.add, {
          method:'POST',
          headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
          body: params.toString()
        });
        const data = await res.json().catch(()=>null);
        if (!data?.success) throw new Error(data?.error || 'Nie udało się dodać.');
        toast('Dodano pozycję/pozycje.');
        resetAddForm();
        await fetchAndRender();
        await fetchStats();
      } catch(e){
        toast(e?.message || 'Błąd sieci przy dodawaniu.','err');
      }
    });
  }

  // =========================
  // 7) Lista klientów (render z AJAX)
  // =========================
  let autoRefreshOn = true;

  async function fetchAndRender(){
    const params = new URLSearchParams({
      live_id: String(CFG.liveId),
      owner_id: String(CFG.ownerId),
      status:   qs('#fltStatus')?.value || '',
      source:   qs('#fltSource')?.value || '',
      res:      qs('#fltRes')?.value || '',
      q:        qs('#globalSearch')?.value || '',
      format:   'html'
    });

    try{
      const res = await fetch(CFG.endpoints.list + '?' + params.toString(), {
        cache:'no-store',
        headers:{ 'X-Requested-With':'XMLHttpRequest' }
      });
      if (!res.ok) throw new Error('HTTP '+res.status);
      const html = await res.text();
      const acc  = qs('#clientAccordion');
      if (acc){
        const prev = autoRefreshOn; autoRefreshOn = false;
        acc.innerHTML = html;
        bindListEvents();
        autoRefreshOn = prev;
      }
    } catch(e){
      toast('Błąd pobierania listy', 'err');
    }
  }

  function bindListEvents(){
    const acc = qs('#clientAccordion'); if (!acc) return;

    // usuń pozycję
    acc.querySelectorAll('[data-del-id]').forEach(btn => {
      if (btn.dataset.bound) return; btn.dataset.bound = '1';
      btn.addEventListener('click', async ()=>{
        const id = btn.dataset.delId; if (!id) return;
        if (!confirm('Usunąć pozycję? Zwolni rezerwację, jeśli dotyczy.')) return;
        try{
          const body = new URLSearchParams({ id, live_id: String(CFG.liveId) });
          const r = await fetch(CFG.endpoints.del, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
          const d = await r.json();
          if (d?.success){ toast('Usunięto pozycję.'); await fetchAndRender(); await fetchStats(); }
          else toast(d?.error || 'Nie udało się usunąć.','err');
        } catch { toast('Błąd sieci przy usuwaniu.','err'); }
      });
    });

    // zmiana ilości (opcjonalny endpoint)
    acc.querySelectorAll('[data-qty-input]').forEach(inp => {
      if (inp.dataset.bound) return; inp.dataset.bound='1';
      inp.addEventListener('change', async ()=>{
        if (!CFG.endpoints.updateQty) return;
        autoRefreshOn=false;
        const rowId = inp.dataset.rowId; const qty = Number(inp.value||1);
        try{
          const body = new URLSearchParams({ id: String(rowId), qty: String(qty), live_id: String(CFG.liveId) });
          const r = await fetch(CFG.endpoints.updateQty, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
          const d = await r.json();
          if (d?.success){ toast('Zapisano ilość.'); fetchStats(); }
          else toast(d?.error || 'Nie zapisano ilości.','err');
        } catch { toast('Błąd sieci przy zapisie ilości.','err'); }
        finally { setTimeout(()=> autoRefreshOn=true, 1500); }
      });
    });

    // lazy-load detali klienta
    acc.querySelectorAll('details[data-client-id]').forEach(d => {
      if (d.dataset.bound) return; d.dataset.bound = '1';

      if (d.open) {
        const host = d.querySelector('[data-client-items]');
        host && loadClientItems(d.dataset.clientId, host);
      }
      d.addEventListener('toggle', () => {
        if (!d.open) return;
        const host = d.querySelector('[data-client-items]');
        if (host && !host.dataset.loaded) {
          host.dataset.loaded = '1';
          loadClientItems(d.dataset.clientId, host);
        }
      });
    });

    async function loadClientItems(clientId, host){
      try{
        const p = new URLSearchParams({ owner_id: String(CFG.ownerId), live_id: String(CFG.liveId), client_id: String(clientId) });
        const res = await fetch('ajax/ajax_live_client_items.php?' + p.toString(), { cache:'no-store' });
        const html = await res.text();
        host.innerHTML = html;
        bindListEvents(); // zbierze ewentualne przyciski wewnątrz
      } catch {
        host.innerHTML = '<div class="text-xs text-rose-600">Nie udało się pobrać pozycji.</div>';
      }
    }
  }

  // =========================
  // 8) Statystyki
  // =========================
  async function fetchStats(){
    try{
      const p = new URLSearchParams({ live_id: String(CFG.liveId), owner_id: String(CFG.ownerId) });
      const res = await fetch(CFG.endpoints.stats + '?' + p.toString(), { cache:'no-store' });
      if (!res.ok) throw 0;
      const d = await res.json();
      if (qs('#statItems')) qs('#statItems').textContent = d?.items ?? '—';
      if (qs('#statRes'))   qs('#statRes').textContent   = d?.reservations ?? '—';
    } catch { /* brak statów nie blokuje UI */ }
  }

  // =========================
  // 9) Finalizacja
  // =========================
  function bindFinalize(){
    const btnOpen = qs('#btnFinalize');
    const modal   = qs('#finalizeModal');
    const errBox  = qs('#finalizeErrors');

    if (!btnOpen || !modal) return;

    btnOpen.addEventListener('click', ()=>{
      errBox?.classList.add('hidden');
      modal.classList.remove('hidden');
      modal.classList.add('flex');
    });

    qs('#btnCancelFinalize')?.addEventListener('click', ()=>{
      modal.classList.add('hidden'); modal.classList.remove('flex');
    });

    qs('#btnConfirmFinalize')?.addEventListener('click', async ()=>{
      try{
        const body = new URLSearchParams({ live_id: String(CFG.liveId), owner_id: String(CFG.ownerId) });
        const r = await fetch(CFG.endpoints.finalize, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
        const d = await r.json();
        if (d?.success){
          toast('Finalizacja zakończona sukcesem ✅');
          modal.classList.add('hidden');
          await fetchAndRender();
          await fetchStats();
        } else {
          const m = d?.error || 'Finalizacja nie powiodła się.';
          if (errBox){ errBox.textContent = m; errBox.classList.remove('hidden'); }
          toast(m, 'err');
        }
      } catch {
        if (errBox){ errBox.textContent = 'Błąd sieci.'; errBox.classList.remove('hidden'); }
        toast('Błąd sieci przy finalizacji.', 'err');
      }
    });
  }

  // =========================
  // 10) Filtry + search + auto-refresh + skróty
  // =========================
  function bindFiltersAndSearch(){
    qs('#btnFilters')?.addEventListener('click', ()=> qs('#filtersOffcanvas')?.classList.remove('hidden'));
    qs('#btnCloseFilters')?.addEventListener('click', ()=> qs('#filtersOffcanvas')?.classList.add('hidden'));
    qs('#btnApplyFilters')?.addEventListener('click', ()=>{ qs('#filtersOffcanvas')?.classList.add('hidden'); fetchAndRender(); });

    qs('#fltStatus')?.addEventListener('change', fetchAndRender);
    qs('#fltSource')?.addEventListener('change', fetchAndRender);
    qs('#fltRes')?.addEventListener('change',   fetchAndRender);

    let tmr=null;
    qs('#globalSearch')?.addEventListener('input', ()=>{
      clearTimeout(tmr); tmr = setTimeout(fetchAndRender, 200);
    });

    setInterval(()=>{ if (autoRefreshOn) fetchAndRender(); }, Number(CFG.autoRefreshMs) || 0);
    qs('#btnRefresh')?.addEventListener('click', fetchAndRender);

    // skróty klawiszowe
    document.addEventListener('keydown', (e)=>{
      const k = e.key.toLowerCase();
      if (e.ctrlKey && k === '/') { e.preventDefault(); qs('#globalSearch')?.focus(); }
      if (!e.ctrlKey && k === 'r') { e.preventDefault(); fetchAndRender(); }
      if (!e.ctrlKey && k === 'f') { e.preventDefault(); qs('#btnFilters')?.click(); }
    });
  }

  // =========================
  // 11) Start
  // =========================
  document.addEventListener('DOMContentLoaded', async ()=>{
    window.OLAJ_LIVE_CFG = CFG; // łatwiej debugować w konsoli
    initSelect2Product();
    bindCustomToggle();
    bindAddForm();
    bindFinalize();
    bindFiltersAndSearch();
    resetAddForm();

    // jeżeli operator wybierze produkt, od razu obsłuż
    const sel = (typeof $!=='undefined') ? $('#product_search') : null;
    if (sel) sel.on('select2:select', checkAvailabilityDebounced);

    // odsłoń do ręcznego testu
    window.__liveCheck = checkAvailability;

    // pierwszy check (dla pre-ustawionych wartości)
    setTimeout(checkAvailabilityDebounced, 0);

    await fetchAndRender();
    await fetchStats();
  });

})();
