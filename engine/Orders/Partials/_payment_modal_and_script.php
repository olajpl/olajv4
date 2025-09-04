<?php
// engine/orders/partials/_payment_modal_and_script.php

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$logInc = __DIR__ . '/../../../includes/log.php';
if (is_file($logInc)) require_once $logInc;

// spróbujmy „zanotować” inicjalizację modala
if (function_exists('logg')) {
  logg('debug', 'ui.payments.modal', 'Modal partial included', [
    'order_id' => (int)($order_id ?? 0),
    'owner_id' => (int)($owner_id ?? 0),
  ], ['context' => 'orders']);
}
?>
<!-- Modal dodania transakcji -->
<div id="pw-modal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
  <div class="absolute inset-0 bg-black/40"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="w-full max-w-xl rounded-lg bg-white shadow-xl">
      <div class="flex items-center justify-between px-4 py-3 border-b">
        <h3 class="text-base font-semibold">Nowa transakcja</h3>
        <button type="button" id="pw-close" class="text-stone-500 hover:text-stone-700" aria-label="Zamknij">✕</button>
      </div>
      <form id="pw-form" class="p-4 space-y-3">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf ?? ($_SESSION['csrf'] ?? ''), ENT_QUOTES) ?>">
        <input type="hidden" name="order_id" value="<?= (int)$order_id ?>">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label class="flex flex-col">
            <span class="text-xs text-stone-500">Grupa (opcjonalnie)</span>
            <select name="order_group_id" class="border rounded-md px-2 py-1.5">
              <option value="">— brak —</option>
              <?php foreach (($groups ?? []) as $g): ?>
                <option value="<?= (int)$g['id'] ?>">#<?= (int)$g['id'] ?> — <?= htmlspecialchars($g['label'] ?? ('Grupa #' . (int)$g['id'])) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="flex flex-col">
            <span class="text-xs text-stone-500">Typ</span>
            <select name="transaction_type" class="border rounded-md px-2 py-1.5" required>
              <option value="wpłata">wpłata</option>
              <option value="zwrot">zwrot</option>
              <option value="korekta">korekta</option>
            </select>
          </label>

          <label class="flex flex-col">
            <span class="text-xs text-stone-500">Status</span>
            <select name="status" class="border rounded-md px-2 py-1.5" required>
              <option value="zaksięgowana">zaksięgowana</option>
              <option value="oczekująca">oczekująca</option>
              <option value="odrzucona">odrzucona</option>
              <option value="anulowana">anulowana</option>
            </select>
          </label>

          <label class="flex flex-col">
            <span class="text-xs text-stone-500">Kwota (PLN)</span>
            <input type="number" name="amount" step="0.01" min="-999999" class="border rounded-md px-2 py-1.5" required>
          </label>

          <label class="flex flex-col">
            <span class="text-xs text-stone-500">Metoda</span>
            <select name="method" class="border rounded-md px-2 py-1.5">
              <option value="">—</option>
              <option value="blik">BLIK</option>
              <option value="card">karta</option>
              <option value="transfer">przelew</option>
              <option value="cash">gotówka</option>
              <option value="cod">pobranie</option>
            </select>
          </label>

          <label class="flex flex-col">
            <span class="text-xs text-stone-500">Provider</span>
            <input type="text" name="provider" placeholder="p24 / payu / stripe / manual" class="border rounded-md px-2 py-1.5">
          </label>

          <label class="flex flex-col md:col-span-2">
            <span class="text-xs text-stone-500">Provider Tx ID (idempotencja)</span>
            <input type="text" name="provider_tx_id" class="border rounded-md px-2 py-1.5">
          </label>

          <label class="flex flex-col md:col-span-2">
            <span class="text-xs text-stone-500">Data zaksięgowania</span>
            <input type="datetime-local" name="booked_at" class="border rounded-md px-2 py-1.5">
          </label>
        </div>

        <div class="flex items-center justify-end gap-2 pt-2 border-t">
          <button type="button" id="pw-cancel" class="px-3 py-1.5 rounded-md border border-stone-300">Anuluj</button>
          <button type="submit" id="pw-submit" class="px-3 py-1.5 rounded-md bg-emerald-600 text-white hover:bg-emerald-700">Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  (() => {
    const orderId = <?= (int)$order_id ?>;
    const $modal = document.getElementById('pw-modal');
    const $open = document.getElementById('btn-add-tx');
    const $close = document.getElementById('pw-close');
    const $cancel = document.getElementById('pw-cancel');
    const $form = document.getElementById('pw-form');
    const $submit = document.getElementById('pw-submit');
    const $refresh = document.getElementById('btn-refresh-tx');

    function escapeHtml(s) {
      return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function fmt(n) {
      return (Number(n || 0).toFixed(2)).replace('.', ',');
    }

    function showModal() {
      $modal.classList.remove('hidden');
      $modal.setAttribute('aria-hidden', 'false');
      // beacon na serwer (niewymagający nowych endpointów – trafia do access logów)
      new Image().src = '/admin/ping.gif?ev=modal_open&order_id=' + orderId + '&t=' + (Date.now());
    }

    function hideModal() {
      $modal.classList.add('hidden');
      $modal.setAttribute('aria-hidden', 'true');
      new Image().src = '/admin/ping.gif?ev=modal_close&order_id=' + orderId + '&t=' + (Date.now());
    }

    async function loadTx() {
      try {
        const res = await fetch('/admin/payments/api/tx_list.php?order_id=' + orderId + '&sort=booked_desc', {
          credentials: 'same-origin'
        });
        const j = await res.json();
        if (!j || !j.ok) {
          console.warn('tx_list error', j);
          alert('Nie udało się pobrać transakcji.');
          return;
        }
        document.getElementById('pw-items-total').textContent = fmt(j.items_total);
        document.getElementById('pw-paid').textContent = fmt(j.paid_amount_pln);
        document.getElementById('pw-due').textContent = fmt(j.due);
        document.getElementById('pw-last').textContent = j.last_payment_at ? escapeHtml(j.last_payment_at) : '—';

        const tb = document.getElementById('pw-tx-body');
        tb.innerHTML = '';
        const rows = Array.isArray(j.transactions) ? j.transactions : [];
        if (!rows.length) {
          tb.innerHTML = '<tr><td colspan="6" class="py-4 text-stone-400 text-center">Brak transakcji</td></tr>';
          return;
        }
        rows.forEach(t => {
          const base = Number((t.amount_pln ?? t.amount) || 0);
          const signed = (t.transaction_type === 'zwrot') ? -Math.abs(base) : base;
          const kw = fmt(signed) + ' zł';
          const tr = document.createElement('tr');
          tr.innerHTML = `
          <td class="py-2 pr-4 whitespace-nowrap">${escapeHtml(t.booked_at || t.transaction_date || '')}</td>
          <td class="py-2 pr-4">${escapeHtml(t.transaction_type)}</td>
          <td class="py-2 pr-4">${escapeHtml(t.status)}</td>
          <td class="py-2 pr-4 font-medium">${kw}</td>
          <td class="py-2 pr-4">${escapeHtml(t.method || '—')}</td>
          <td class="py-2 pr-4">${escapeHtml(t.provider || '')}${t.provider_tx_id ? ' / ' + escapeHtml(t.provider_tx_id) : ''}</td>
        `;
          tb.appendChild(tr);
        });
      } catch (e) {
        console.error(e);
        alert('Błąd sieci podczas pobierania transakcji.');
      }
    }

    $open?.addEventListener('click', showModal);
    $close?.addEventListener('click', hideModal);
    $cancel?.addEventListener('click', hideModal);
    $refresh?.addEventListener('click', () => {
      new Image().src = '/admin/ping.gif?ev=refresh_click&order_id=' + orderId + '&t=' + (Date.now());
      loadTx();
    });

    $form?.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        $submit.disabled = true;
        $submit.textContent = 'Zapisuję…';
        const data = new FormData($form);
        const res = await fetch('/admin/payments/api/tx_add.php', {
          method: 'POST',
          credentials: 'same-origin',
          body: data
        });
        const j = await res.json();
        if (!j || !j.ok) {
          alert('Nie udało się dodać transakcji: ' + (j?.error || 'unknown'));
          // prosty beacon do access logów (pozwala zobaczyć częstość błędów frontowych)
          new Image().src = '/admin/ping.gif?ev=tx_add_fail&order_id=' + orderId + '&t=' + (Date.now());
          return;
        }
        // sukces – odśwież listę
        new Image().src = '/admin/ping.gif?ev=tx_add_ok&order_id=' + orderId + '&t=' + (Date.now());
        hideModal();
        await loadTx();
      } catch (e) {
        console.error(e);
        alert('Błąd sieci podczas zapisu transakcji.');
        new Image().src = '/admin/ping.gif?ev=tx_add_err&order_id=' + orderId + '&t=' + (Date.now());
      } finally {
        $submit.disabled = false;
        $submit.textContent = 'Zapisz';
      }
    });

    // start
    loadTx();
  })();
</script>