(function () {
  // =========================
  // 1) Helpery
  // =========================
  const CFG = {
    endpoints: {
      add: 'ajax_add_live_product.php',
      list: 'ajax_live_temp_list.php',
      del: 'ajax_delete_live_product.php',
      finalize: 'ajax_finalize_batch.php',
      availability: 'ajax_check_availability.php',   // istnieje wg ustaleń
      updateQty: 'ajax_update_live_qty.php',         // OPCJONALNIE
    },
    autoRefreshMs: 5000,
  };

  function qs(sel, root = document) { return root.querySelector(sel); }
  function qsa(sel, root = document) { return Array.from(root.querySelectorAll(sel)); }
  function money(n) {
    try { return new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN' }).format(Number(n) || 0); }
    catch { return (Number(n) || 0).toFixed(2) + ' zł'; }
  }
  function toast(msg, type = 'ok') {
    // Minimalistyczny feedback bez zależności — możesz podmienić na swój system notyfikacji.
    const el = document.createElement('div');
    el.className = `fixed z-50 top-4 right-4 px-4 py-2 rounded shadow text-white ${type==='ok'?'bg-green-600':'bg-red-600'}`;
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(()=> el.remove(), 2500);
  }

  // URL-encoded POST
  async function postForm(url, dataObj) {
    const body = new URLSearchParams();
    Object.entries(dataObj).forEach(([k, v]) => {
      if (Array.isArray(v)) {
        v.forEach(val => body.append(k, String(val)));
      } else {
        if (v !== undefined && v !== null) body.append(k, String(v));
      }
    });
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body
    });
    const txt = await res.text();
    // Spróbuj JSON, jeśli się nie uda — oddaj tekst
    try { return JSON.parse(txt); } catch { return txt; }
  }

  // Debounce
  function debounce(fn, wait = 300) {
    let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), wait); };
  }

  // =========================
  // 2) Select2 — inicjalizacja
  // =========================
  function initSelect2() {
    // Produkt
    if (window.jQuery && jQuery.fn.select2) {
      
    url: 'ajax/ajax_product_search.php', // upewnij się, że ścieżka jest /admin/live/ajax/ajax_product_search.php relatywnie do strony
    delay: 250,
    dataType: 'json',
    data: params => ({ q: params.term || '' }),
    processResults: (data) => {
      // obsłuż oba kształty: {results:[...]} i {items:[...]} + łagodna degradacja
      const arr = (data && (data.results || data.items)) || [];
      // Na wszelki wypadek: jeśli backend zwróci error, pokaż pustą listę (i możesz zalogować)
      if (!Array.isArray(arr)) return { results: [] };

      const mapPrice = (p) => {
        const price = (p.unit_price_netto ?? p.unit_price ?? 0);
        try {
          return new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN' }).format(Number(price) || 0);
        } catch {
          return ((Number(price) || 0).toFixed(2) + ' zł');
        }
      };

      return {
        results: arr.map(p => ({
          id: p.id,
          // Preferuj tekst z backendu (p.text), inaczej zlep name + sku + cena
          text: p.text || `${p.name || 'bez nazwy'} • ${p.sku || p.code || p.ean || p.twelve_nc || '—'} • ${mapPrice(p)}`
        }))
      };
    },
    cache: true
  },
  language: {
    inputTooShort: () => 'Wpisz co najmniej 1 znak',
    searching: () => 'Szukam…',
    noResults: () => 'Brak wyników'
  }
});

      // Klienci (dla każdego wiersza)
      jQuery('.client-search').each(function () {
        const $sel = jQuery(this);
        if ($sel.hasClass('select2-hidden-accessible')) return; // nie dubluj
        $sel.select2({
          width: '100%',
          placeholder: 'Wybierz klienta…',
          ajax: {
           url: 'ajax/ajax_client_search.php',
            delay: 250,
            dataType: 'json',
            data: params => ({ q: params.term || '' }),
            processResults: data => ({
              results: (data.items || []).map(c => ({
                id: c.id,
                text: `${c.name || c.email || ('ID:'+c.id)}`
              }))
            })
          }
        });
      });

      // Autofocus na polu wyszukiwania
      document.addEventListener('select2:open', () => {
        document.querySelector('.select2-container--open .select2-search__field')?.focus();
      });
    }}

  // =========================
  // 3) Formularz: wiersze klientów
  // =========================
  function bindClientRows() {
    const container = qs('#clientRows');
    if (!container) return;

    container.addEventListener('click', (e) => {
      if (e.target.classList.contains('addRow')) {
        e.preventDefault();
        const rows = qsa('.client-row', container);
        const idx = rows.length;
        const div = document.createElement('div');
        div.className = 'grid grid-cols-12 gap-3 client-row';
        div.innerHTML = `
          <div class="col-span-9">
            <select name="clients[${idx}][id]" class="client-search w-full"></select>
          </div>
          <div class="col-span-2">
            <input type="number" name="clients[${idx}][qty]" value="1" min="1" step="1" class="w-full border rounded px-3 py-2">
          </div>
          <div class="col-span-1 flex items-end">
            <button type="button" class="removeRow w-full border rounded px-2 py-2 hover:bg-gray-50">🗑️</button>
          </div>`;
        container.appendChild(div);
        initSelect2();
      }
      if (e.target.classList.contains('removeRow')) {
        e.preventDefault();
        const row = e.target.closest('.client-row');
        if (row) row.remove();
        availabilityCheckDebounced(); // ilość się mogła zmienić
      }
    });

    // Zmiana ilości — przelicz dostępność
    container.addEventListener('input', (e) => {
      if (e.target.matches('input[name^="clients"][name$="[qty]"]')) {
        availabilityCheckDebounced();
      }
    });
  }

  // =========================
  // 4) Toggle custom fields
  // =========================
  function bindCustomToggle() {
    const chk = qs('#is_custom');
    const box = qs('#customFields');
    if (!chk || !box) return;
    function sync() {
      box.classList.toggle('hidden', !chk.checked);
      availabilityCheckDebounced(); // custom wyłączony → sprawdzamy dostępność katalogowego
    }
    chk.addEventListener('change', sync);
    sync();
  }

  // =========================
  // 5) Formularz: dodawanie
  // =========================
  function serializeAddForm(form) {
    // Budujemy URLSearchParams bez FormData (zgodnie z ustaleniem)
    const data = {};
    const els = Array.from(form.elements).filter(el => el.name && !el.disabled);
    els.forEach(el => {
      const name = el.name;
      let val = el.type === 'checkbox' ? (el.checked ? el.value || '1' : '') : el.value;
      if (val === '') return;

      // Obsługa wielokrotnych nazw (np. clients[0][id])
      if (data[name] === undefined) data[name] = val;
      else if (Array.isArray(data[name])) data[name].push(val);
      else data[name] = [data[name], val];
    });
    return data;
  }

  async function handleAddSubmit(e) {
    e.preventDefault();
    const form = e.currentTarget;
    const btn = form.querySelector('[type="submit"]');
    const btnTxt = btn.textContent;

    btn.disabled = true; btn.textContent = 'Dodaję…';

    try {
      const payload = serializeAddForm(form);
      // Dla pewności dodajemy live_id (gdyby ktoś usunął hidden)
      if (!payload['live_id']) payload['live_id'] = window.OLAJ_LIVE?.liveId || '';

      const res = await postForm(CFG.endpoints.add, payload);

      if (res && res.success) {
        toast('Dodano pozycje do listy.', 'ok');
        resetAddForm(form);
        await fetchAndRenderList();
      } else {
        const msg = (res && (res.error || res.message)) || 'Nie udało się dodać pozycji.';
        toast(msg, 'err');
      }
    } catch (err) {
      console.error(err);
      toast('Błąd połączenia przy dodawaniu.', 'err');
    } finally {
      btn.disabled = false; btn.textContent = btnTxt;
    }
  }

  function resetAddForm(form) {
    // Wyczyść produkt (select2)
    if (window.jQuery && jQuery.fn.select2) {
      jQuery('#product_search').val(null).trigger('change');
    } else {
      const ps = qs('#product_search'); if (ps) ps.value = '';
    }

    // Schowaj custom i wyczyść pola
    const chk = qs('#is_custom'); if (chk) chk.checked = false;
    const box = qs('#customFields'); if (box) {
      box.classList.add('hidden');
      qsa('input', box).forEach(i => i.value = '');
    }

    // Zresetuj wiersze klientów do jednego
    const container = qs('#clientRows');
    if (container) {
      container.innerHTML = `
        <div class="grid grid-cols-12 gap-3 client-row">
          <div class="col-span-9">
            <select name="clients[0][id]" class="client-search w-full"></select>
          </div>
          <div class="col-span-2">
            <input type="number" name="clients[0][qty]" value="1" min="1" step="1" class="w-full border rounded px-3 py-2">
          </div>
          <div class="col-span-1 flex items-end">
            <button type="button" class="addRow w-full border rounded px-2 py-2 hover:bg-gray-50">➕</button>
          </div>
        </div>`;
      initSelect2();
    }
  }

  function bindAddForm() {
    const form = qs('#addProductForm');
    if (!form) return;
    form.addEventListener('submit', handleAddSubmit);
  }

  // =========================
  // 6) Lista klientów (ładowanie)
  // =========================
  async function fetchAndRenderList() {
    const wrap = qs('#liveProductList');
    if (!wrap) return;
    const liveId = wrap.getAttribute('data-live-id');
    const url = `${CFG.endpoints.list}?live_id=${encodeURIComponent(liveId)}`;
    try {
      const res = await fetch(url);
      wrap.innerHTML = await res.text();
      bindListEvents();
    } catch (e) {
      wrap.innerHTML = `<div class="text-sm text-red-600">Nie udało się pobrać listy.</div>`;
    }
  }

  // =========================
  // 7) Lista: delegacje (usuń, qty)
  // =========================
  function bindListEvents() {
    const wrap = qs('#liveProductList');
    if (!wrap) return;

    // Usuń pozycję
    wrap.addEventListener('click', async (e) => {
      const delBtn = e.target.closest('.btn-delete');
      if (delBtn) {
        e.preventDefault();
        const id = delBtn.getAttribute('data-id'); // live_temp_id
        if (!id) return;

        delBtn.disabled = true;
        try {
          const res = await postForm(CFG.endpoints.del, { id });
          if (res && res.success) {
            toast('Usunięto pozycję.', 'ok');
            await fetchAndRenderList();
          } else {
            toast((res && (res.error || res.message)) || 'Nie udało się usunąć.', 'err');
          }
        } catch (err) {
          console.error(err);
          toast('Błąd połączenia przy usuwaniu.', 'err');
        } finally {
          delBtn.disabled = false;
        }
      }
    });

    // Zmiana qty (opcjonalnie, jeśli dodasz endpoint updateQty)
    wrap.addEventListener('change', debounce(async (e) => {
      const inp = e.target;
      if (!inp.matches('.qty-input')) return;
      const liveTempId = inp.getAttribute('data-id');
      const qty = inp.value;
      if (!liveTempId || !qty) return;

      try {
        const res = await postForm(CFG.endpoints.updateQty, { id: liveTempId, qty });
        if (res && res.success) {
          toast('Zmieniono ilość.', 'ok');
          await fetchAndRenderList();
        } else {
          toast((res && (res.error || res.message)) || 'Nie udało się zmienić ilości.', 'err');
        }
      } catch (err) {
        console.error(err);
        toast('Błąd połączenia przy zmianie ilości.', 'err');
      }
    }, 400));
  }

  // =========================
  // 8) Finalizacja (all-or-nothing)
  // =========================
  function bindFinalize() {
    const btn = qs('#btnFinalize');
    if (!btn) return;
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      const liveId = btn.getAttribute('data-live-id');
      if (!liveId) return;

      const old = btn.textContent;
      btn.disabled = true; btn.textContent = 'Finalizuję…';

      try {
        const res = await postForm(CFG.endpoints.finalize, { live_id: liveId });
        if (res && res.success) {
          toast('Wysłano podsumowania. Rezerwacje zaksięgowane ✅', 'ok');
          await fetchAndRenderList();
        } else {
          toast((res && (res.error || res.message)) || 'Finalizacja nie powiodła się — nic nie zmieniono.', 'err');
        }
      } catch (err) {
        console.error(err);
        toast('Błąd połączenia przy finalizacji.', 'err');
      } finally {
        btn.disabled = false; btn.textContent = old;
      }
    });
  }

  // =========================
  // 9) Sprawdzanie dostępności (debounce)
  // =========================
  const availabilityCheckDebounced = debounce(checkAvailability, 350);

  function sumRequestedQty() {
    let total = 0;
    qsa('#clientRows input[name^="clients"][name$="[qty]"]').forEach(inp => {
      const v = parseInt(inp.value, 10);
      if (!isNaN(v) && v > 0) total += v;
    });
    return total || 1;
  }

  async function checkAvailability() {
    const hint = qs('#availabilityHint');
    const isCustom = qs('#is_custom')?.checked;
    const productId = (window.jQuery && jQuery('#product_search').val()) || qs('#product_search')?.value;

    if (!hint) return;
    if (isCustom || !productId) { hint.classList.add('hidden'); return; }

    const qty = sumRequestedQty();
    hint.textContent = 'Sprawdzam dostępność…'; hint.classList.remove('hidden');

    try {
      const liveId = window.OLAJ_LIVE?.liveId || '';
      const res = await postForm(CFG.endpoints.availability, { product_id: productId, qty, live_id: liveId });
      // Zakładamy odpowiedź { ok:true, available:int, stock:int, reserved:int }
      if (res && (res.ok || res.success)) {
        hint.textContent = `Dostępnych: ${res.available ?? res.stock ?? '?'} (w tym już zarezerw.: ${res.reserved ?? 0})`;
      } else {
        hint.textContent = 'Brak danych o dostępności.';
      }
    } catch {
      hint.textContent = 'Nie udało się sprawdzić dostępności.';
    }
  }

  function bindAvailability() {
    const productSel = qs('#product_search');
    if (!productSel) return;

    // Select2 change
    if (window.jQuery && jQuery.fn.select2) {
      jQuery('#product_search').on('change', () => availabilityCheckDebounced());
    } else {
      productSel.addEventListener('change', () => availabilityCheckDebounced());
    }
  }

  // =========================
  // 10) Ręczny refresh + auto-refresh
  // =========================
  function bindRefresh() {
    const btn = qs('#btnRefreshList');
    if (!btn) return;
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      fetchAndRenderList().catch(()=>{});
    });
  }

  function autoRefreshMaybe() {
    const status = (window.OLAJ_LIVE?.status || '').toLowerCase();
    if (status === 'live') {
      setInterval(() => { fetchAndRenderList().catch(()=>{}); }, CFG.autoRefreshMs);
    }
  }

  // =========================
  // 11) Boot
  // =========================
  function boot() {
    initSelect2();
    bindClientRows();
    bindCustomToggle();
    bindAddForm();
    bindFinalize();
    bindAvailability();
    bindRefresh();
    fetchAndRenderList().catch(()=>{});
    autoRefreshMaybe();

    // Pierwsze wywołanie dostępności (gdy operator już ma wybrany produkt)
    availabilityCheckDebounced();
  }

  document.addEventListener('DOMContentLoaded', boot);
})();
