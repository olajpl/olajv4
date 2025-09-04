<?php

/** Wymaga: $order, $csrf; helper: e() */
?>
<div class="rounded-xl border border-stone-200">
    <div class="px-4 py-3 border-b bg-stone-50 font-semibold">Skaner produktów</div>
    <div class="p-4">
        <form id="scan-form" class="flex items-center gap-2 max-w-xl">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <input type="text" id="scan-code" name="code" autofocus placeholder="Zeskanuj kod (SKU/EAN)"
                class="flex-1 px-3 py-2 rounded-lg border border-stone-300" />
            <button class="px-3 py-2 rounded-lg border border-stone-300 hover:bg-stone-100" type="submit">Skanuj</button>
        </form>
        <div id="scan-msg" class="mt-2 text-sm text-stone-600"></div>
    </div>
</div>
<script>
    document.getElementById('scan-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const msg = document.getElementById('scan-msg');
        const code = form.code.value.trim();
        if (!code) {
            return;
        }
        msg.textContent = 'Skanuję…';

        try {
            const res = await fetch('/admin/orders/api/scan_product.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF': form.csrf.value
                },
                body: JSON.stringify({
                    order_id: parseInt(form.order_id.value, 10),
                    code: code,
                    inc: 1 // +1 sztuka
                })
            });
            const data = await res.json();
            if (!data.ok) {
                throw new Error(data.error || 'Błąd');
            }

            const it = data.item;
            const row = document.querySelector('[data-item-id="' + it.id + '"]');
            if (row) {
                const qtyEl = row.querySelector('[data-role="prepared-progress"]');
                const chip = row.querySelector('[data-role="prepared-chip"]');
                if (qtyEl) {
                    qtyEl.textContent = `${it.packed_count} / ${it.qty}`;
                }
                if (chip) {
                    chip.textContent = it.done ? 'przygotowane' : 'w toku';
                    chip.className = 'text-xs px-2 py-0.5 rounded-full ' + (it.done ?
                        'bg-green-100 border border-green-200 text-green-800' :
                        'bg-yellow-50 border border-yellow-200 text-yellow-800');
                }
            }

            msg.textContent = `OK: ${it.sku || ''} → ${it.packed_count}/${it.qty}`;
            form.code.value = '';
        } catch (err) {
            msg.textContent = '❌ ' + err.message;
        }
    });
</script>