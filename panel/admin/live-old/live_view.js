// === LIVE VIEW FULL SCRIPT ===
$(function () {
  const liveId = <?= (int)$stream_id ?>;

  // --- INIT: Select2 for product ---
  $('#product_search').select2({
    placeholder: 'Wyszukaj produkt...',
    width: '100%',
    ajax: {
      url: 'ajax_product_search.php',
      dataType: 'json',
      delay: 250,
      data: params => ({ q: params.term }),
      processResults: data => ({ results: data }),
      cache: true
    },
    minimumInputLength: 2
  });

  // --- INIT: Select2 for client ---
  $('.client-search').select2({
    placeholder: 'Szukaj klienta...',
    width: '100%',
    ajax: {
      url: 'ajax_client_search.php',
      dataType: 'json',
      delay: 250,
      data: params => ({ q: params.term }),
      processResults: data => ({ results: data }),
      cache: true
    },
    minimumInputLength: 2
  });

  // --- Custom product toggle ---
  $('#toggleCustomProduct').on('change', function () {
    $('#customProductFields').toggleClass('hidden', !this.checked);
  });

  // --- Add client row ---
  $('#addClientRow').on('click', function () {
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
        data: params => ({ q: params.term }),
        processResults: data => ({ results: data }),
        cache: true
      },
      minimumInputLength: 2
    });
  });

  // --- Remove client row ---
  $(document).on('click', '.remove-row', function () {
    $(this).closest('.grid').remove();
  });

  // --- Submit form ---
  $('#addProductForm').on('submit', async function (e) {
    e.preventDefault();
    const formData = new FormData(this);
    const submitBtn = $(this).find('button[type="submit"]');

    submitBtn.prop('disabled', true).text('⏳ Dodawanie...');

    try {
      const res = await fetch('ajax_add_live_product.php', {
        method: 'POST',
        body: formData
      });
      const json = await res.json();

      if (json.success) {
        this.reset();
        $('#customProductFields').addClass('hidden');
        $('#product_search').val(null).trigger('change');

        $('#clientProductRows').html(`
          <div class="grid grid-cols-12 gap-2">
            <div class="col-span-7">
              <select name="client_search[]" class="client-search w-full"></select>
            </div>
            <div class="col-span-3">
              <input type="number" name="qty[]" value="1" placeholder="Ilość" class="border p-2 rounded w-full" />
            </div>
            <div class="col-span-2 flex items-center">
              <button type="button" class="remove-row text-red-600 hover:underline">Usuń</button>
            </div>
          </div>
        `);

        $('.client-search').select2({
          placeholder: 'Szukaj klienta...',
          width: '100%',
          ajax: {
            url: 'ajax_client_search.php',
            dataType: 'json',
            delay: 250,
            data: params => ({ q: params.term }),
            processResults: data => ({ results: data }),
            cache: true
          },
          minimumInputLength: 2
        });

        alert('✅ Dodano produkt');

        if (liveId) {
          fetch('ajax_live_temp_list.php?live_id=' + liveId)
            .then(r => r.text())
            .then(html => $('#liveProductList').html(html));
        }
      } else {
        alert('❌ Błąd: ' + (json.error || 'Nieznany'));
      }
    } catch (err) {
      alert('❌ Błąd połączenia');
    } finally {
      submitBtn.prop('disabled', false).text('➕ Dodaj');
    }
  });

  // --- Autofocus on Select2 open ---
  $(document).on('select2:open', () => {
    document.querySelector('.select2-container--open .select2-search__field')?.focus();
  });
});
