<?php
/** Wymaga: $order, $csrf; helper: e() */
$groupId = (int)($order['group_id'] ?? ($_GET['group_id'] ?? 0));
?>
<div class="rounded-xl border border-stone-200">
  <div class="px-4 py-3 border-b bg-stone-50 font-semibold">Skaner produktów</div>
  <div class="p-4">
    <form id="scan-form" class="flex items-center gap-2 max-w-xl" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
      <?php if ($groupId): ?>
        <input type="hidden" name="group_id" value="<?= $groupId ?>">
      <?php endif; ?>
      <input type="text" id="scan-code" name="code" inputmode="text" placeholder="Zeskanuj kod (SKU/EAN)"
             class="flex-1 px-3 py-2 rounded-lg border border-stone-300" autofocus />
      <button class="px-3 py-2 rounded-lg border border-stone-300 hover:bg-stone-100" type="submit">Skanuj</button>
    </form>
    <div id="scan-msg" class="mt-2 text-sm text-stone-600"></div>
  </div>
</div>

<script>
(function(){
  const form = document.getElementById('scan-form');
  const codeEl = document.getElementById('scan-code');
  const msg = document.getElementById('scan-msg');
  let busy = false;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (busy) return;
    const code = codeEl.value.trim();
    if (!code) return;

    busy = true;
    msg.textContent = 'Skanuję…';

    try {
      const payload = {
        order_id: parseInt(form.order_id.value, 10),
        code,
        inc: 1
      };
      if (form.group_id) payload.group_id = parseInt(form.group_id.value, 10) || undefined;

      const res = await fetch('/admin/orders/api/scan_product.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF': form.csrf.value,
          'X-JSON': '1' // sygnał dla bootstrapa, by włączyć JSON guard
        },
        cache: 'no-store',
        body: JSON.stringify(payload)
      });

      // spróbuj najpierw JSON; jeśli poleci błąd typu "<br><b>Warning", to fallback do text
      let data;
      const ctype = res.headers.get('content-type') || '';
      if (ctype.includes('application/json')) {
        data = await res.json();
      } else {
        const text = await res.text();
        try { data = JSON.parse(text); }
        catch {
          throw new Error('Nieprawidłowa odpowiedź serwera (HTML).');
        }
      }

      if (!res.ok || !data.ok) {
        throw new Error((data && (data.error || data.message)) || ('HTTP ' + res.status));
      }

      const it = data.item;
      const row = document.querySelector('[data-item-id="' + it.id + '"]');
      if (row) {
        const qtyEl = row.querySelector('[data-role="prepared-progress"]');
        const chip = row.querySelector('[data-role="prepared-chip"]');
        if (qtyEl) qtyEl.textContent = `${it.packed_count} / ${it.qty}`;
        if (chip) {
          chip.textContent = it.done ? 'przygotowane' : 'w toku';
          chip.className = 'text-xs px-2 py-0.5 rounded-full ' + (it.done
            ? 'bg-green-100 border border-green-200 text-green-800'
            : 'bg-yellow-50 border border-yellow-200 text-yellow-800');
        }
        // podświetl na chwilę
        row.classList.add('ring-2','ring-green-300');
        setTimeout(()=>row.classList.remove('ring-2','ring-green-300'), 500);
        // przewiń w razie potrzeby
        if (!rowIsInViewport(row)) row.scrollIntoView({behavior:'smooth', block:'center'});
      }

      msg.textContent = `OK: ${it.sku || ''} → ${it.packed_count}/${it.qty}`;
      codeEl.value = '';
      codeEl.focus();
    } catch (err) {
      msg.textContent = '❌ ' + (err.message || 'Błąd');
    } finally {
      busy = false;
    }
  });

  function rowIsInViewport(el) {
    const r = el.getBoundingClientRect();
    return r.top >= 0 && r.bottom <= (window.innerHeight || document.documentElement.clientHeight);
  }

  // barcode skanery wysyłają Enter – upewnij się, że fokus wraca
  window.addEventListener('pageshow', () => { codeEl.focus(); });
})();
</script>
