<?php
ob_start(); // tymczasowy bufor — zapobiega wysyłaniu nagłówków

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/layout_header.php';
// teraz bezpiecznie użyjesz $_SESSION

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$stream_id = (int)($_GET['id'] ?? 0);

if ($stream_id <= 0) {
  echo '<div class="text-red-600">Brak ID transmisji.</div>';
  require_once __DIR__ . '/../../layout/layout_footer.php';
  exit;
}

$stmt = $pdo->prepare("SELECT * FROM live_streams WHERE id = ? AND owner_id = ? LIMIT 1");
$stmt->execute([$stream_id, $owner_id]);
$stream = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$stream) {
  echo '<div class="text-red-600">Nie znaleziono transmisji.</div>';
  require_once __DIR__ . '/../../layout/layout_footer.php';
  exit;
}
?>

<a href="index.php" class="text-sm text-blue-600 hover:underline flex items-center gap-1 mb-4">
  <span class="text-lg">&larr;</span> Wróć do listy transmisji
</a>

<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-bold">🎥 <?= htmlspecialchars($stream['title']) ?></h1>
  <form method="post" action="ajax_finalize_batch.php">
    <input type="hidden" name="live_id" value="<?= (int)$stream_id ?>"
      <button id="btnFinalize" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
    Wyślij podsumowania
    </button>
  </form>
</div>

<div class="flex flex-col lg:flex-row gap-6">
  <!-- LEWA KOLUMNA -->
  <div class="flex-1">
    <div class="bg-white p-4 rounded-xl shadow mb-4">
      <h2 class="text-lg font-semibold mb-2">➕ Dodaj produkt</h2>
      <form id="addProductForm" method="post" enctype="multipart/form-data">
        <input type="hidden" name="owner_id" value="<?= (int)$_SESSION['user']['owner_id'] ?>">
        <input type="hidden" name="live_id" value="<?= (int)$stream_id ?>">

        <div class="grid grid-cols-12 gap-4">
          <div class="col-span-6">
            <select id="product_search" name="product_search" class="border p-2 rounded w-full"></select>
          </div>
          <div class="col-span-6 text-right">
            <label class="inline-flex items-center space-x-2">
              <input type="checkbox" id="toggleCustomProduct" name="is_custom" class="form-checkbox">
              <span class="text-sm">Produkt niestandardowy</span>
            </label>
          </div>
        </div>

        <div id="customProductFields" class="grid grid-cols-1 md:grid-cols-4 gap-4 hidden">
          <input type="text" name="custom_name" placeholder="Nazwa" class="border p-2 rounded w-full" />
          <input type="text" name="custom_sku" placeholder="SKU" class="border p-2 rounded w-full" />
          <input type="number" name="custom_price" step="0.01" placeholder="Cena brutto" class="border p-2 rounded w-full" />
          <select name="custom_vat" class="border p-2 rounded w-full">
            <option value="23">23%</option>
            <option value="8">8%</option>
            <option value="5">5%</option>
            <option value="0">0%</option>
          </select>
        </div>

        <div id="clientProductRows" class="space-y-2">
          <div class="grid grid-cols-12 gap-2">
            <div class="col-span-7">
              <select name="client_search[]" class="client-search border p-2 rounded w-full"></select>
            </div>
            <div class="col-span-3">
              <input type="number" name="qty[]" placeholder="Ilość" class="border p-2 rounded w-full" />
            </div>
            <div class="col-span-2 flex items-center">
              <button type="button" class="remove-row text-red-600 hover:underline">Usuń</button>
            </div>
          </div>
        </div>

        <div class="flex gap-4">
          <button type="button" id="addClientRow" class="bg-purple-600 text-white px-4 py-2 rounded hover:bg-purple-700">
            ➕ Dodaj klienta
          </button>
          <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
            ➕ Dodaj
          </button>
        </div>
      </form>
    </div>

    <div class="bg-white p-4 rounded-xl shadow">
      <h2 class="text-lg font-semibold mb-2">📦 Przypisane produkty</h2>
      <!-- Zakładki: Klienci / Produkty -->
      <section id="live-tabs" class="bg-white rounded-2xl shadow-sm border border-gray-100">
        <!-- Header + sterowanie -->
        <div class="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-6 py-3 border-b">
          <div class="flex items-center gap-1" role="tablist" aria-label="Przypisania">
            <button id="tab-clients" role="tab" aria-selected="true" aria-controls="panel-clients"
              class="inline-block px-4 py-2 rounded-t-lg text-sm font-medium border-b-2 border-blue-600 text-blue-700 focus:outline-none focus:ring">
              Klienci
            </button>
            <button id="tab-products" role="tab" aria-selected="false" aria-controls="panel-products"
              class="inline-block px-4 py-2 rounded-t-lg text-sm font-medium border-b-2 border-transparent text-gray-600 hover:text-gray-800 focus:outline-none focus:ring">
              Produkty
            </button>
          </div>

          <div class="flex items-center gap-2">
            <label class="inline-flex items-center gap-2 text-sm text-gray-600">
              <input id="autoRefresh" type="checkbox" class="rounded border-gray-300">
              Auto-odśwież (10s)
            </label>
            <button id="btnRefresh" class="px-3 py-2 text-sm rounded-lg border hover:bg-gray-50">
              Odśwież
            </button>
          </div>
        </div>

        <!-- Panele -->
        <div class="p-4 sm:p-6">
          <!-- Panel: Klienci -->
          <div id="panel-clients" role="tabpanel" aria-labelledby="tab-clients">
            <div id="clients-status" class="hidden"></div>
            <div id="clients-list" class="divide-y"></div>
            <div id="clients-empty" class="hidden text-sm text-gray-500 italic">Brak przypisań dla klientów.</div>
          </div>

          <!-- Panel: Produkty -->
          <div id="panel-products" role="tabpanel" aria-labelledby="tab-products" class="hidden">
            <div id="products-status" class="hidden"></div>
            <div id="products-list" class="divide-y"></div>
            <div id="products-empty" class="hidden text-sm text-gray-500 italic">Brak przypisań dla produktów.</div>
          </div>
        </div>
      </section>
    </div>
  </div>

  <!-- PRAWA KOLUMNA -->
  <div class="w-full lg:w-[30%]">
    <div class="bg-white p-4 rounded-xl shadow" style="height: calc(100vh - 200px); overflow-y: auto;">
      <h2 class="text-lg font-semibold mb-2">💬 Komentarze z transmisji</h2>
      <div id="commentList" class="space-y-3">[tu komentarze AJAX]</div>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
  window.liveId = <?= (int)($_GET['id'] ?? 0) ?>;
  $(function() {
    const liveId = <?= (int)$stream_id ?>;
    // Select2: produkt
    $('#product_search').select2({
      placeholder: 'Wyszukaj produkt...',
      width: '100%',
      ajax: {
        url: 'ajax_product_search.php',
        dataType: 'json',
        delay: 250,
        data: params => ({
          q: params.term
        }),
        processResults: data => ({
          results: data
        }),
        cache: true
      },
      minimumInputLength: 2
    });

    // Select2: klient
    $('.client-search').select2({
      placeholder: 'Szukaj klienta...',
      width: '100%',
      ajax: {
        url: 'ajax_client_search.php',
        dataType: 'json',
        delay: 250,
        data: params => ({
          q: params.term
        }),
        processResults: data => ({
          results: data
        }),
        cache: true
      },
      minimumInputLength: 2
    });

    // toggle niestandardowego produktu
    $('#toggleCustomProduct').on('change', function() {
      $('#customProductFields').toggleClass('hidden', !this.checked);
    });

    // dodaj nowego klienta
    $('#addClientRow').on('click', function() {
      const row = $(`
      <div class="grid grid-cols-12 gap-2">
        <div class="col-span-7">
          <select name="client_search[]" class="client-search w-full"></select>
        </div>
        <div class="col-span-3">
          <input type="number" name="qty[]" placeholder="Ilość" class="border p-2 rounded w-full" />
        </div>
        <div class="col-span-2 flex items-center">
          <button type="button" class="remove-row text-red-600 hover:underline">Usuń</button>
        </div>
      </div>
    `);
      $('#clientProductRows').append(row);
      row.find('.client-search').select2({
        placeholder: 'Szukaj klienta...',
        width: '100%',
        ajax: {
          url: 'ajax_client_search.php',
          dataType: 'json',
          delay: 250,
          data: params => ({
            q: params.term
          }),
          processResults: data => ({
            results: data
          }),
          cache: true
        },
        minimumInputLength: 2
      });
    });

    // usuń klienta
    $(document).on('click', '.remove-row', function() {
      $(this).closest('.grid').remove();
    });

    // submit formularza
    $('#addProductForm').on('submit', async function(e) {
      e.preventDefault();

      const submitBtn = $(this).find('button[type="submit"]');
      submitBtn.prop('disabled', true).text('⏳ Dodawanie...');

      const form = e.target;
      const formParams = new URLSearchParams(new FormData(form));
      formParams.append('live_id', window.liveId); // ← DODAJ TO RĘCZNIE!
      console.log('▶️ FORM SEND:', [...formParams.entries()]);
      for (const [k, v] of formParams.entries()) console.log(k, v);
      try {
        const res = await fetch('ajax_add_live_product.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: formParams
        });

        const json = await res.json();
        console.log('✅ RESPONSE:', json);

        if (json.success) {
          alert('✅ OK: ' + (json.msg || 'dodano'));
        } else {
          alert('❌ Błąd: ' + json.error);
        }
      } catch (err) {
        alert('❌ Błąd połączenia: ' + err.message);
      } finally {
        submitBtn.prop('disabled', false).text('➕ Dodaj');
      }
    });


    // autofocus w Select2
    $(document).on('select2:open', () => {
      document.querySelector('.select2-container--open .select2-search__field')?.focus();
    });

  });
</script>
<script>
  (() => {
      // --- Konfiguracja ---
      const LIVE_ID = <?= (int)$stream_id ?>;
      // podpinamy wasz live_id
      const ENDPOINT = 'ajax_live_temp_list.php';

      // --- Elementy DOM ---
      const tabClients = document.getElementById('tab-clients');
      const tabProducts = document.getElementById('tab-products');
      const panelClients = document.getElementById('panel-clients');
      const panelProducts = document.getElementById('panel-products');

      const clientsList = document.getElementById('clients-list');
      const clientsEmpty = document.getElementById('clients-empty');
      const clientsStatus = document.getElementById('clients-status');

      const productsList = document.getElementById('products-list');
      const productsEmpty = document.getElementById('products-empty');
      const productsStatus = document.getElementById('products-status');

      const btnRefresh = document.getElementById('btnRefresh');
      const autoRefresh = document.getElementById('autoRefresh');

      let activeTab = 'clients';
      let autoTimer = null;
      let isFetching = false;

      // --- Helpery UI ---
      function setTab(tab) {
        activeTab = tab;
        const isClients = tab === 'clients';

        // aria-selected + style
        tabClients.setAttribute('aria-selected', isClients ? 'true' : 'false');
        tabProducts.setAttribute('aria-selected', isClients ? 'false' : 'true');

        tabClients.classList.toggle('border-blue-600', isClients);
        tabClients.classList.toggle('text-blue-700', isClients);
        tabClients.classList.toggle('text-gray-600', !isClients);
        tabProducts.classList.toggle('border-blue-600', !isClients);
        tabProducts.classList.toggle('text-blue-700', !isClients);
        tabProducts.classList.toggle('text-gray-600', isClients);

        panelClients.classList.toggle('hidden', !isClients);
        panelProducts.classList.toggle('hidden', isClients);

        // Ładuj dane panelu aktywnego
        fetchAndRender(tab);
      }

      function showStatus(el, type, msg) {
        el.className = 'mb-3 text-sm px-3 py-2 rounded';

        if (type === 'error') {
          el.classList.add('bg-red-50', 'text-red-700', 'border', 'border-red-100');
        } else {
          el.classList.add('bg-blue-50', 'text-blue-700', 'border', 'border-blue-100');
        }

        el.textContent = msg;
        el.classList.remove('hidden');
      }


      function hideStatus(el) {
        el.classList.add('hidden');
      }

      function fmtMoney(n) {
        if (n == null || isNaN(n)) return '—';
        return new Intl.NumberFormat('pl-PL', {
          style: 'currency',
          currency: 'PLN',
          minimumFractionDigits: 2
        }).format(n);
      }

      // --- Render: Klienci ---
      function renderClients(payload) {
        console.log('Payload:', payload);

        // payload: { groups: [{ client_id, client_name, phone?, count_items, sum_qty, sum_value, rows: [{id, product_id, product_name, sku, qty, price, vat_rate, note}] }], totals: {...} }
        clientsList.innerHTML = '';
        if (!payload || !payload.groups || payload.groups.length === 0) {
          clientsEmpty.classList.remove('hidden');
          return;
        }
        clientsEmpty.classList.add('hidden');

        const frag = document.createDocumentFragment();
        payload.groups.forEach(group => {
          const row = document.createElement('div');
          row.className = 'py-3';

          const header = document.createElement('button');
          header.type = 'button';
          header.className = 'w-full flex items-center justify-between gap-4 text-left';
          header.setAttribute('aria-expanded', 'false');

          header.innerHTML = `
        <div class="flex items-center gap-3">
          <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-50 text-blue-700 text-sm font-semibold">${(group.client_name || 'Klient #'+group.client_id).slice(0,2).toUpperCase()}</span>
          <div>
            <div class="font-medium">${group.client_name || 'Brak nazwy'} <span class="text-xs text-gray-400">#${group.client_id}</span></div>
            <div class="text-xs text-gray-500">${group.phone || ''}</div>
          </div>
        </div>
        <div class="flex items-center gap-6 text-sm text-gray-600">
          <span>Pozycji: <b class="text-gray-900">${group.count_items}</b></span>
          <span>Ilość: <b class="text-gray-900">${group.sum_qty}</b></span>
          <span>Wartość: <b class="text-gray-900">${fmtMoney(group.sum_value)}</b></span>
          <svg class="w-4 h-4 text-gray-400 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.08 1.04l-4.25 4.25a.75.75 0 01-1.06 0L5.21 8.27a.75.75 0 01.02-1.06z"/></svg>
        </div>
      `;

          const panel = document.createElement('div');
          panel.className = 'mt-3 hidden';

          // tabela produktów klienta
          const table = document.createElement('div');
          table.className = 'overflow-x-auto border rounded-lg';
          const rowsHtml = (group.rows || []).map(item => `
  <div class="grid grid-cols-12 items-center px-3 py-2 border-b last:border-b-0 text-sm">
    <div class="col-span-5">
      <div class="font-medium">${item.product_name || item.custom_name || 'Produkt'}</div>
      <div class="text-xs text-gray-500">${item.sku ? 'SKU: ' + item.sku : ''}${item.note ? ' • ' + item.note : ''}</div>
    </div>
    <div class="col-span-2 text-right">${item.qty}</div>
    <div class="col-span-2 text-right">${fmtMoney(item.price)}</div>
    <div class="col-span-2 text-right">${fmtMoney((item.qty || 0) * (item.price || 0))}</div>
    <div class="col-span-1 text-right">
    <button type="button" class="btn-edit text-blue-500 text-xs mr-2" data-id="${item.id}" data-qty="${item.qty}" data-price="${item.price}" data-note="${item.note || ''}" title="Edytuj">✏️</button>

      <button type="button" class="btn-delete text-red-500 text-xs" data-id="${item.id}" title="Usuń">🗑️</button>
    </div>
  </div>
`).join('');




          table.innerHTML = `
  <div class="grid grid-cols-12 px-3 py-2 bg-gray-50 text-xs font-semibold text-gray-600 rounded-t-lg">
    <div class="col-span-5">Produkt</div>
    <div class="col-span-2 text-right">Ilość</div>
    <div class="col-span-2 text-right">Cena</div>
    <div class="col-span-2 text-right">Wartość</div>
    <div class="col-span-1 text-right">Akcje</div>
  </div>

  ${rowsHtml || '<div class="px-3 py-4 text-sm text-gray-500 italic">Brak pozycji.</div>'}

  <div class="grid grid-cols-12 px-3 py-2 bg-gray-50 text-sm font-semibold rounded-b-lg">
    <div class="col-span-9 text-right">Razem</div>
    <div class="col-span-2 text-right">${fmtMoney(group.sum_value || 0)}</div>
    <div class="col-span-1"></div>
  </div>
`;





          panel.appendChild(table);

          header.addEventListener('click', () => {
            const open = panel.classList.toggle('hidden');
            header.setAttribute('aria-expanded', (!open).toString());
          });

          row.appendChild(header);
          row.appendChild(panel);
          frag.appendChild(row);
        });

        clientsList.appendChild(frag);
      }

      // --- Render: Produkty ---
      function renderProducts(payload) {
        productsList.innerHTML = '';
        if (!payload || !payload.groups || payload.groups.length === 0) {
          productsEmpty.classList.remove('hidden');
          return;
        }
        productsEmpty.classList.add('hidden');

        const frag = document.createDocumentFragment();
        payload.groups.forEach(group => {
          const row = document.createElement('div');
          row.className = 'py-3';

          const header = document.createElement('button');
          header.type = 'button';
          header.className = 'w-full flex items-center justify-between gap-4 text-left';
          header.setAttribute('aria-expanded', 'false');

          header.innerHTML = `
      <div>
        <div class="font-medium">${group.product_name || 'Produkt'} <span class="text-xs text-gray-400">${group.sku ? '• ' + group.sku : ''} #${group.product_id}</span></div>
        <div class="text-xs text-gray-500">Klienci: ${group.count_clients} • Ilość: ${group.sum_qty} • Wartość: ${fmtMoney(group.sum_value)}</div>
      </div>
      <svg class="w-4 h-4 text-gray-400 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.08 1.04l-4.25 4.25a.75.75 0 01-1.06 0L5.21 8.27a.75.75 0 01.02-1.06z"/></svg>
    `;

          const panel = document.createElement('div');
          panel.className = 'mt-3 hidden';

          const table = document.createElement('div');
          table.className = 'overflow-x-auto border rounded-lg';

          const rowsHtml = (group.rows || []).map(item => `
      <div class="grid grid-cols-12 items-center px-3 py-2 border-b last:border-b-0 text-sm">
        <div class="col-span-5">
          <div class="font-medium">${item.client_name || 'Klient #' + item.client_id}</div>
          <div class="text-xs text-gray-500">${item.note || ''}</div>
        </div>
        <div class="col-span-2 text-right">${item.qty}</div>
        <div class="col-span-2 text-right">${fmtMoney(item.price)}</div>
        <div class="col-span-2 text-right">${fmtMoney((item.qty || 0) * (item.price || 0))}</div>
        <div class="col-span-1 text-right">
        <button type="button" class="btn-edit text-blue-500 text-xs mr-2" data-id="${item.id}" data-qty="${item.qty}" data-price="${item.price}" data-note="${item.note || ''}" title="Edytuj">✏️</button>

          <button type="button" class="btn-delete text-red-500 text-xs" data-id="${item.id}" title="Usuń">🗑️</button>
        </div>
      </div>
    `).join('');

          table.innerHTML = `
      <div class="grid grid-cols-12 px-3 py-2 bg-gray-50 text-xs font-semibold text-gray-600 rounded-t-lg">
        <div class="col-span-5">Klient</div>
        <div class="col-span-2 text-right">Ilość</div>
        <div class="col-span-2 text-right">Cena</div>
        <div class="col-span-2 text-right">Wartość</div>
        <div class="col-span-1 text-right">Akcje</div>
      </div>

      ${rowsHtml || '<div class="px-3 py-4 text-sm text-gray-500 italic">Brak przypisanych klientów.</div>'}

      <div class="grid grid-cols-12 px-3 py-2 bg-gray-50 text-sm font-semibold rounded-b-lg">
        <div class="col-span-9 text-right">Razem</div>
        <div class="col-span-2 text-right">${fmtMoney(group.sum_value || 0)}</div>
        <div class="col-span-1"></div>
      </div>
    `;

          panel.appendChild(table);

          header.addEventListener('click', () => {
            const open = panel.classList.toggle('hidden');
            header.setAttribute('aria-expanded', (!open).toString());
          });

          row.appendChild(header);
          row.appendChild(panel);
          frag.appendChild(row);
        });

        productsList.appendChild(frag);
      }




    }


    document.addEventListener('click', async e => {
      const deleteBtn = e.target.closest('.btn-delete');
      if (!deleteBtn) return;

      console.log('✅ Kliknięto delete', e.target);

      const id = deleteBtn.dataset.id;
      if (!confirm('Na pewno usunąć produkt z przypisania?')) return;

      try {
        const res = await fetch('ajax_delete_live_product.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: `live_temp_id=${encodeURIComponent(id)}`
        });

        const json = await res.json();
        if (json.success) {
          fetchAndRender(activeTab);
        } else {
          alert('❌ Błąd: ' + (json.error || 'Nieznany'));
        }
      } catch (err) {
        alert('❌ Błąd połączenia: ' + err.message);
      }
    }); async function fetchData(mode) {
      if (!LIVE_ID) throw new Error('Brak LIVE_ID.');
      const url = `${ENDPOINT}?live_id=${encodeURIComponent(LIVE_ID)}&mode=${encodeURIComponent(mode)}`;
      const res = await fetch(url);
      if (!res.ok) throw new Error('Błąd pobierania (' + res.status + ')');
      return res.json();
    }

    document.addEventListener('click', e => {
      const editBtn = e.target.closest('.btn-edit');
      if (!editBtn) return;

      const row = editBtn.closest('.grid');
      const id = editBtn.dataset.id;
      const qty = editBtn.dataset.qty || '';
      const price = editBtn.dataset.price || '';
      const note = editBtn.dataset.note || '';

      // Zastąp row edytowalnym formularzem
      row.innerHTML = `
    <div class="col-span-5">
      <input type="text" class="edit-note border p-1 rounded w-full text-sm" placeholder="Notatka" value="${note}" />
    </div>
    <div class="col-span-2 text-right">
      <input type="number" class="edit-qty border p-1 rounded w-full text-sm text-right" value="${qty}" />
    </div>
    <div class="col-span-2 text-right">
      <input type="number" step="0.01" class="edit-price border p-1 rounded w-full text-sm text-right" value="${price}" />
    </div>
    <div class="col-span-2 text-right text-sm italic">–</div>
    <div class="col-span-1 text-right flex gap-1">
      <button class="btn-save text-green-600 text-xs font-bold" data-id="${id}">💾</button>
      <button class="btn-cancel text-gray-400 text-xs font-bold">✖</button>
    </div>
  `;
    });

    // Obsługa zapisu
    document.addEventListener('click', async e => {
      const deleteBtn = e.target.closest('.btn-delete');
      if (!deleteBtn) return;

      if (!confirm('Na pewno usunąć produkt z przypisania?')) return;

      try {
        const res = await fetch('ajax_delete_live_product.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: new URLSearchParams({
            id: deleteBtn.dataset.id,
            live_id: window.liveId
          })
        });

        const json = await res.json();
        if (json.success) {
          fetchAndRender(activeTab);
        } else {
          alert('❌ Błąd: ' + (json.error || 'Nieznany'));
        }
      } catch (err) {
        alert('❌ Błąd połączenia: ' + err.message);
      }
    });



    // Anuluj
    document.addEventListener('click', e => {
      const cancelBtn = e.target.closest('.btn-cancel');
      if (cancelBtn) fetchAndRender(activeTab);
    });

    // --- Zdarzenia ---
    tabClients.addEventListener('click', () => setTab('clients')); tabProducts.addEventListener('click', () => setTab('products')); btnRefresh.addEventListener('click', () => fetchAndRender(activeTab)); autoRefresh.addEventListener('change', () => {
      if (autoRefresh.checked) {
        autoTimer = setInterval(() => fetchAndRender(activeTab), 10000);
      } else if (autoTimer) {
        clearInterval(autoTimer);
        autoTimer = null;
      }
    });



    document.querySelector('#btnFinalize')?.addEventListener('click', () => {
      if (!confirm('Czy na pewno wysłać podsumowania?')) return;

      const liveId = window.liveId;
      if (!liveId) {
        alert('❌ Nie ustawiono live_id!');
        return;
      }
      console.log('liveId:', liveId);
      fetch('ajax_finalize_batch.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: new URLSearchParams({
            live_id: liveId
          })
        })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            alert(`✅ Podsumowania wysłane.\nPrzeniesiono pozycji: ${data.result?.moved ?? 'brak danych'}`);

          } else {
            alert('❌ Błąd finalize: ' + data.error);
          }
        })
        .catch(err => {
          alert('❌ Błąd żądania: ' + err.message);
        });
    });

    // Funkcja odpalająca przeładunek zakładki „Klienci” po finalize
    function loadClientTab() {
      setTab('clients');
    })(); // ← zamyka i uruchamia IIFE
</script>