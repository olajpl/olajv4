/*
  admin/live/js/live.js — Olaj.pl V4
  Wersja z endpointami w podkatalogu admin/live/ajax/
  Dopasowany 1:1 do nowego admin/live/view.php (selektory, flow)
  Wymaga: jQuery + Select2
*/
(function(){
  // =========================
  // 1) Konfiguracja i helpery
  // =========================
  const CFG = window.OLAJ_LIVE_CFG || {
    liveId: Number(document.querySelector('input[name="live_id"]').value || 0),
    ownerId: Number(document.querySelector('input[name="owner_id"]').value || 0),
    operatorId: Number(document.querySelector('input[name="operator_user_id"]').value || 0),
    endpoints: {
      add: 'ajax/ajax_add_live_product.php',
      list: 'ajax/ajax_live_temp_list.php',
      del: 'ajax/ajax_delete_live_product.php',
      finalize: 'ajax/ajax_finalize_batch.php',
      availability: 'ajax/ajax_check_availability.php',
      updateQty: 'ajax/ajax_update_live_qty.php', // opcjonalnie
      stats: 'ajax/ajax_live_quick_stats.php'     // opcjonalnie
    },
    autoRefreshMs: 5000
  };

  const qs = (s, r=document) => r.querySelector(s);
  const qsa = (s, r=document) => Array.from(r.querySelectorAll(s));
  const money = (n) => { try { return new Intl.NumberFormat('pl-PL',{style:'currency',currency:'PLN'}).format(Number(n)||0); } catch(_) { return ((Number(n)||0).toFixed(2))+' zł'; } };
  const toast = (msg, type='ok')=>{
    const wrap = qs('#toastWrap') || (()=>{ const d=document.createElement('div'); d.id='toastWrap'; d.className='fixed top-4 right-4 space-y-2 z-50'; document.body.appendChild(d); return d; })();
    const el=document.createElement('div');
    el.className='px-4 py-2 rounded-xl shadow border text-sm bg-white ' + (type==='err'?'border-rose-200 text-rose-700':'border-emerald-200 text-emerald-700');
    el.textContent=msg; wrap.appendChild(el); setTimeout(()=>el.remove(), 3500);
  };

  // =========================
  // 2) Select2 init + wiersze klientów
  // =========================
  function initSelect2Product(){
    if (typeof $ === 'undefined' || !$('#product_search').select2) return;
    $('#product_search').select2({
		minimumInputLength: 1,
      ajax: {
        url: 'ajax/ajax_product_search.php',
        dataType: 'json', delay: 200,
        data: p => ({ q:p.term, owner_id: CFG.ownerId, live_id: CFG.liveId }),
        processResults: d => ({ results: Array.isArray(d) ? d : (d?.results || []) })
      },
      placeholder: 'Wpisz nazwę lub kod…', width:'100%'
    }).on('select2:open', ()=> setTimeout(()=> document.querySelector('.select2-search__field')?.focus(), 0))
      .on('select2:select', checkAvailabilityDebounced)
      .on('select2:clear', hideAvailability);
  }

function initSelect2Client(sel) {
  $(sel).select2({
    ajax: {
      url: 'ajax/ajax_client_search.php',
      dataType: 'json',
      delay: 200,
      data: params => ({ q: params.term || '' }),
      processResults: (data) => {
        const arr = Array.isArray(data) ? data : (data?.results || []);
        return { results: arr.map(c => ({ id: c.id, text: c.text || c.name })) };
      },
      cache: true
    },
    placeholder: 'Wybierz klienta…',
    minimumInputLength: 1,
    width: 'resolve'
  }).on('select2:open', () => {
    document.querySelector('.select2-search__field')?.focus();
  });
}


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
    qs('#clientRows').appendChild(row);
    initSelect2Client(row.querySelector('.client-search'));

    row.addEventListener('click', (ev)=>{
      const btn = ev.target.closest('[data-act]'); if (!btn) return;
      const act = btn.dataset.act; const qty=row.querySelector('input[name="qty[]"]'); const val=Number(qty.value||1);
      if (act==='plus') qty.value = val+1; if (act==='minus') qty.value = Math.max(1, val-1); if (act==='remove') row.remove();
      recalcFormSummary();
    });
    row.querySelector('input[name="qty[]"]').addEventListener('input', recalcFormSummary);
    recalcFormSummary();
  }

  function recalcFormSummary(){
    const sumClients = qsa('#clientRows .client-search').length;
    const sumQty = qsa('#clientRows input[name="qty[]"]').map(i=>Number(i.value||0)).reduce((a,b)=>a+b,0);
    qs('#sumClients').textContent = sumClients; qs('#sumQty').textContent = sumQty; qs('#sumUnits').textContent = sumQty;
  }

  // Toggle custom/katalog
  function bindCustomToggle(){
    const cb=qs('#toggleCustom'); if (!cb) return;
    cb.addEventListener('change', (e)=>{
      const on=e.target.checked; qs('#customFields').classList.toggle('hidden', !on);
      if (on && typeof $ !== 'undefined') { $('#product_search').val(null).trigger('change'); hideAvailability(); }
    });
  }

  // =========================
  // 3) Dostępność (debounce)
  // =========================
  let availTmr=null;
  function showAvailability(text, kind='warn'){
    const b=qs('#availabilityBadge'); if (!b) return;
    b.textContent=text; b.classList.remove('hidden','badge-ok','badge-warn','badge-err');
    b.classList.add(kind==='ok'?'badge-ok':kind==='err'?'badge-err':'badge-warn');
  }
  function hideAvailability(){ qs('#availabilityBadge')?.classList.add('hidden'); }
  function checkAvailabilityDebounced(){ clearTimeout(availTmr); availTmr=setTimeout(checkAvailability, 300); }
  async function checkAvailability(){
    if (qs('#toggleCustom')?.checked) return hideAvailability();
    const productId = (typeof $!=='undefined') ? $('#product_search').val() : null; if (!productId) return hideAvailability();
    showAvailability('Sprawdzam dostępność…','warn');
    try{
      const params = new URLSearchParams({ product_id: productId, owner_id: CFG.ownerId, live_id: CFG.liveId });
      const res = await fetch(CFG.endpoints.availability + '?' + params.toString());
      const data = await res.json();
      if (data?.ok){
        const txt = `Dostępne: ${data.available ?? '—'} • Zarezerwowane: ${data.reserved ?? '—'}`;
        showAvailability(txt, (Number(data.available)>0?'ok':'err'));
      } else showAvailability(data?.msg || 'Brak danych','err');
    } catch(_){ showAvailability('Błąd sprawdzania','err'); }
  }

  // =========================
  // 4) Formularz Dodaj produkt
  // =========================
  function resetAddForm(){
    if (typeof $ !== 'undefined') $('#product_search').val(null).trigger('change');
    const cb=qs('#toggleCustom'); if (cb) cb.checked=false; qs('#customFields')?.classList.add('hidden');
    const rows=qs('#clientRows'); if (rows){ rows.innerHTML=''; newClientRow(); }
    hideAvailability(); recalcFormSummary();
  }

  function bindAddForm(){
    const form=qs('#addProductForm'); if (!form) return;
    qs('#btnAddClientRow')?.addEventListener('click', newClientRow);
    qs('#btnClearForm')?.addEventListener('click', resetAddForm);
    form.addEventListener('submit', async (ev)=>{
      ev.preventDefault();
      const fd = new FormData(form);
      const params = new URLSearchParams(); fd.forEach((v,k)=> params.append(k,v));
      params.append('is_custom', qs('#toggleCustom')?.checked ? '1':'0');
      try{
        const res = await fetch(CFG.endpoints.add, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: params.toString() });
        const data = await res.json();
        if (data?.success){ toast('Dodano pozycję/pozycje.'); resetAddForm(); await fetchAndRender(); await fetchStats(); }
        else toast(data?.error || 'Nie udało się dodać.','err');
      } catch(_){ toast('Błąd sieci przy dodawaniu.','err'); }
    });
  }

  // =========================
  // 5) Lista klientów (render z AJAX)
  // =========================
  let autoRefreshOn = true;
  async function fetchAndRender(){
    const params = new URLSearchParams({
      live_id: CFG.liveId, owner_id: CFG.ownerId,
      status: qs('#fltStatus')?.value || '', source: qs('#fltSource')?.value || '', res: qs('#fltRes')?.value || '',
      q: qs('#globalSearch')?.value || ''
    });
    const res = await fetch(CFG.endpoints.list + '?' + params.toString());
     if (!res.ok) { toast('Błąd pobierania listy ('+res.status+')','err'); return; }
 const html = await res.text();
    const acc = qs('#clientAccordion'); if (acc){ acc.innerHTML = html; bindListEvents(); }
  }

  function bindListEvents(){
    const acc = qs('#clientAccordion'); if (!acc) return;
    // delete
    acc.querySelectorAll('[data-del-id]').forEach(btn => {
      btn.addEventListener('click', async ()=>{
        const id = btn.dataset.delId; if (!id) return; if (!confirm('Usunąć pozycję? Zwolni rezerwację, jeśli dotyczy.')) return;
        try{
          const body = new URLSearchParams({ id, live_id: CFG.liveId });
          const r = await fetch(CFG.endpoints.del, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
          const d = await r.json();
          if (d?.success){ toast('Usunięto pozycję.'); await fetchAndRender(); await fetchStats(); }
          else toast(d?.error || 'Nie udało się usunąć.','err');
        } catch(_){ toast('Błąd sieci przy usuwaniu.','err'); }
      }, { once:true });
    });

    // qty update (opcjonalnie)
    acc.querySelectorAll('[data-qty-input]').forEach(inp => {
      inp.addEventListener('change', async ()=>{
        if (!CFG.endpoints.updateQty) return;
        autoRefreshOn=false;
        const rowId = inp.dataset.rowId; const qty = Number(inp.value||1);
        try{
          const body = new URLSearchParams({ id: rowId, qty, live_id: CFG.liveId });
          const r = await fetch(CFG.endpoints.updateQty, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
          const d = await r.json();
          if (d?.success){ toast('Zapisano ilość.'); fetchStats(); }
          else toast(d?.error || 'Nie zapisano ilości.','err'); }
        catch(_){ toast('Błąd sieci przy zapisie ilości.','err'); }
        finally { setTimeout(()=> autoRefreshOn=true, 1500); }
      });
    });
  }

  // =========================
  // 6) Statystyki
  // =========================
  async function fetchStats(){
    try{
      const p = new URLSearchParams({ live_id: CFG.liveId, owner_id: CFG.ownerId });
      const res = await fetch(CFG.endpoints.stats + '?' + p.toString());
      if (!res.ok) throw 0; const d = await res.json();
      qs('#statItems') && (qs('#statItems').textContent = d?.items ?? '—');
      qs('#statRes') && (qs('#statRes').textContent   = d?.reservations ?? '—');
    } catch(_){ /* opcjonalny fallback do parsowania z listy */ }
  }

  // =========================
  // 7) Finalizacja
  // =========================
  function bindFinalize(){
    const btnOpen = qs('#btnFinalize'); const modal=qs('#finalizeModal'); const err=qs('#finalizeErrors');
    if (!btnOpen || !modal) return;
    btnOpen.addEventListener('click', ()=>{ err?.classList.add('hidden'); modal.classList.remove('hidden'); modal.classList.add('flex'); });
    qs('#btnCancelFinalize')?.addEventListener('click', ()=>{ modal.classList.add('hidden'); modal.classList.remove('flex'); });
    qs('#btnConfirmFinalize')?.addEventListener('click', async ()=>{
      try{
        const body = new URLSearchParams({ live_id: CFG.liveId, owner_id: CFG.ownerId });
        const r = await fetch(CFG.endpoints.finalize, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
        const d = await r.json();
        if (d?.success){ toast('Finalizacja zakończona sukcesem.'); modal.classList.add('hidden'); await fetchAndRender(); await fetchStats(); }
        else { if (err){ err.textContent = d?.error || 'Finalizacja nie powiodła się.'; err.classList.remove('hidden'); } toast(d?.error || 'Finalizacja nie powiodła się.','err'); }
      } catch(_){ if (err){ err.textContent='Błąd sieci.'; err.classList.remove('hidden'); } toast('Błąd sieci przy finalizacji.','err'); }
    });
  }

  // =========================
  // 8) Filtry + search + auto‑refresh + skróty
  // =========================
  function bindFiltersAndSearch(){
    qs('#btnFilters')?.addEventListener('click', ()=> qs('#filtersOffcanvas')?.classList.remove('hidden'));
    qs('#btnCloseFilters')?.addEventListener('click', ()=> qs('#filtersOffcanvas')?.classList.add('hidden'));
    qs('#btnApplyFilters')?.addEventListener('click', ()=>{ qs('#filtersOffcanvas')?.classList.add('hidden'); fetchAndRender(); });

    qs('#fltStatus')?.addEventListener('change', fetchAndRender);
    qs('#fltSource')?.addEventListener('change', fetchAndRender);
    qs('#fltRes')?.addEventListener('change', fetchAndRender);

    let tmr=null; qs('#globalSearch')?.addEventListener('input', ()=>{ clearTimeout(tmr); tmr=setTimeout(fetchAndRender, 200); });

    setInterval(()=>{ if (autoRefreshOn) fetchAndRender(); }, CFG.autoRefreshMs);
    qs('#btnRefresh')?.addEventListener('click', fetchAndRender);

    document.addEventListener('keydown', (e)=>{
      if (e.ctrlKey && e.key === '/') { e.preventDefault(); qs('#globalSearch')?.focus(); }
      if (!e.ctrlKey && e.key.toLowerCase() === 'r') { e.preventDefault(); fetchAndRender(); }
      if (!e.ctrlKey && e.key.toLowerCase() === 'f') { e.preventDefault(); qs('#btnFilters')?.click(); }
    });
  }

  // =========================
  // 9) Start
  // =========================
  document.addEventListener('DOMContentLoaded', async ()=>{
    initSelect2Product();
    bindCustomToggle();
    bindAddForm();
    bindFinalize();
    bindFiltersAndSearch();
    resetAddForm();
    await fetchAndRender();
    await fetchStats();
  });
})();
