
export default function initForm() {
  const $form = document.querySelector('#addProductForm');
  const $product = document.querySelector('#product_search');
  const $customFields = document.querySelector('#customFields');
  const $clientsList = document.querySelector('#clientsList');

  function toggleCustomFields(data) {
    $customFields.classList.toggle('hidden', data.id !== 'custom');
  }

  function buildClientRow(client) {
    const row = document.createElement('div');
    row.className = 'flex items-center gap-4';
    row.innerHTML = `
      <input type="hidden" name="clients[]" value="${client.id}">
      <span>${client.name}</span>
      <input type="number" name="qty[${client.id}]" value="1" class="w-16 border rounded px-2 py-1" min="1">
      <button type="button" class="text-red-500 hover:underline removeClient">Usuń</button>
    `;
    return row;
  }

  function bindClientRemoval() {
    $clientsList.addEventListener('click', e => {
      if (e.target.classList.contains('removeClient')) {
        e.target.closest('div').remove();
      }
    });
  }

  function bindFormSubmit() {
    $form.addEventListener('submit', e => {
      e.preventDefault();
      const formData = new URLSearchParams(new FormData($form));
      fetch('ajax/ajax_add_live_product.php', {
        method: 'POST',
        body: formData
      })
        .then(res => res.json())
        .then(data => {
          if (data.ok || data.success) {
            $product.value = '';
            $product.dispatchEvent(new Event('change'));
            $customFields.classList.add('hidden');
            $clientsList.innerHTML = '';
            initForm(); // re-init select2
            window.fetchAndRenderProductList?.();
          } else {
            alert('Błąd: ' + data.error);
          }
        });
    });
  }

$('.select2-product').select2({
  ajax: {
    url: 'ajax/ajax_product_search.php',
    dataType: 'json',
    delay: 250,
    data: params => ({ q: params.term || '' }),
    processResults: (data) => {
      // obsługa obu wariantów: [..] lub {results:[..]}
      const arr = Array.isArray(data) ? data : (data?.results || []);
      return {
        results: arr.map(p => ({
          id: p.id,
          text: p.text || [p.name, p.sku ? `(${p.sku})` : ''].filter(Boolean).join(' ')
        }))
      };
    },
    cache: true
  },
  placeholder: 'Wpisz nazwę lub kod...',
  minimumInputLength: 1,
  width: '100%'
}).on('select2:select', e => {

  $(document).on('select2:open', () => {
    document.querySelector('.select2-search__field')?.focus();
  });

  bindClientRemoval();
  bindFormSubmit();
}
