<?php
/**
 * Wymaga: $groups, $itemsByGroup, $dues, $paid, $applied, $order, $csrf, $canEditLocal
 * Helpery: e(), zl()
 */

/** Helper miniaturki — deklarujemy RAZ, poza pętlami */
if (!function_exists('productThumbUrl')) {
    function productThumbUrl(?int $pid): string {
        if (!$pid) return '/assets/img/placeholder.svg';
        return "/uploads/products/{$pid}/main.webp";
    }
}
?>

<div class="rounded-xl border border-stone-200">
    <div class="px-4 py-3 border-b bg-stone-50 font-semibold">Grupy (rozwijane)</div>
    <div class="p-2 md:p-3 space-y-2">
        <?php if (!$groups): ?>
            <div class="text-stone-500 p-2">Brak grup.</div>
        <?php else: ?>
            <?php foreach ($groups as $g):
                $gid = (int)$g['id'];
                $gDue     = (float)($dues[$gid] ?? 0.0);
                $gPaid    = (float)($paid[$gid] ?? 0.0);
                $gApplied = (float)($applied[$gid] ?? 0.0);
                $gStatus  = ($gApplied <= 0.01) ? 'nieopłacona' : (($gApplied + 0.01 < $gDue) ? 'częściowa' : (abs($gApplied - $gDue) <= 0.01 ? 'opłacona' : 'nadpłata'));

                $groupItems = $itemsByGroup[$gid] ?? [];
                $sum = 0.0;
                foreach ($groupItems as $it) {
                    $sum += (float)$it['qty'] * (float)($it['unit_price'] ?? 0.0); // NETTO
                }
            ?>
                <div class="border rounded-lg overflow-hidden">
                    <!-- Header grupy -->
                    <button type="button"
                        class="w-full flex items-center justify-between px-3 py-2 bg-white hover:bg-stone-50"
                        data-toggle="grp-<?= $gid ?>">
                        <div class="flex items-center gap-2 text-left">
                            <div class="font-medium">Grupa #<?= $gid ?></div>
                            <?php if (!empty($g['checkout_completed'])): ?>
                                <span class="text-xs px-2 py-0.5 rounded-full bg-green-100 border border-green-200 text-green-800">zakończona</span>
                            <?php else: ?>
                                <span class="text-xs px-2 py-0.5 rounded-full bg-yellow-50 border border-yellow-200 text-yellow-800">w toku</span>
                            <?php endif; ?>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-stone-100 border border-stone-200"><?= e($gStatus) ?></span>
                            <?php if (!empty($g['checkout_token'])): ?>
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-stone-50 border border-stone-200">token: <?= e((string)$g['checkout_token']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-4 text-sm text-stone-700">
                            <div>Suma: <b><?= zl($sum) ?></b></div>
                            <div class="text-stone-500">Utw.: <?= e((string)($g['created_at'] ?? '')) ?></div>
                            <svg class="h-4 w-4 text-stone-500 transition-transform" data-icon-for="grp-<?= $gid ?>" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </button>

                    <!-- Content grupy -->
                    <div id="grp-<?= $gid ?>" class="hidden border-t bg-white">
                        <div class="p-3 overflow-auto">
                            <table class="w-full text-sm table-fixed">
                                <colgroup>
                                    <col />                      <!-- Produkt -->
                                    <col style="width:120px" />  <!-- Ilość -->
                                    <col style="width:140px" />  <!-- Cena (netto) -->
                                    <col style="width:110px" />  <!-- VAT % -->
                                    <col style="width:140px" />  <!-- Wartość (netto) -->
                                    <col style="width:1%" />     <!-- Akcje -->
                                </colgroup>
                                <thead class="bg-stone-50">
                                    <tr>
                                        <th class="text-left px-2 py-1.5">Produkt</th>
                                        <th class="text-right px-2 py-1.5">Ilość</th>
                                        <th class="text-right px-2 py-1.5">Cena (netto)</th>
                                        <th class="text-right px-2 py-1.5">VAT %</th>
                                        <th class="text-right px-2 py-1.5">Wartość (netto)</th>
                                        <th class="px-2 py-1.5"></th>
                                    </tr>
                                </thead>

                                <tbody>
<?php foreach ($groupItems as $it):
    $iid    = (int)($it['id'] ?? 0);
    $qty    = (float)($it['qty'] ?? 0.0);
    $priceN = (float)($it['unit_price'] ?? 0.0);   // NETTO
    $vat    = (float)($it['vat_rate'] ?? 23.0);    // %
    $rowNet = $qty * $priceN;                      // NETTO
    $sku    = $it['sku'] ?? null;

    $packed = (float)($it['packed_count'] ?? 0.0);
    $done   = ($qty > 0) && ($packed + 0.0001 >= $qty);

    $formId = "upd-{$gid}-{$iid}";
?>
    <tr class="border-t" data-item-id="<?= $iid ?>">
        <!-- kol. 1: miniatura + nazwa + badge -->
        <td class="px-2 py-1.5">
            <div class="flex items-center gap-2">
                <img src="<?= e(productThumbUrl(isset($it['product_id']) ? (int)$it['product_id'] : null)) ?>"
                     alt="" class="w-8 h-8 rounded object-cover border bg-white" loading="lazy">
                <div class="min-w-0">
                    <div class="font-medium truncate">
                        <?= e((string)($it['name'] ?? $it['product_name'] ?? 'Produkt')) ?>
                    </div>
                    <?php if (!empty($sku)): ?>
                        <div class="text-stone-500 text-xs">sku: <?= e((string)$sku) ?></div>
                    <?php endif; ?>
                    <div class="text-xs text-stone-500 mt-0.5">
                        <span data-role="prepared-progress"><?= (int)$packed ?> / <?= e((string)$qty) ?></span>
                    </div>
                </div>

                <?php if ($canEditLocal && empty($g['checkout_completed'])): ?>
                <button type="button"
                        class="ml-2 text-xs px-2 py-0.5 rounded-full border <?= $done ? 'bg-green-100 border-green-200 text-green-800' : 'bg-yellow-50 border-yellow-200 text-yellow-800' ?>"
                        data-action="toggle-packed"
                        data-item-id="<?= $iid ?>"
                        data-order-id="<?= (int)$order['id'] ?>"
                        data-group-id="<?= $gid ?>"
                        data-csrf="<?= e($csrf) ?>">
                    <?= $done ? 'spakowane' : 'do spakowania' ?>
                </button>
                <?php endif; ?>
            </div>
        </td>

        <!-- kol. 2: ilość -->
        <td class="px-2 py-1.5 text-right">
            <?php if ($canEditLocal && empty($g['checkout_completed'])): ?>
                <input form="<?= $formId ?>" type="number" name="qty" step="0.001" min="0"
                       value="<?= e(number_format($qty, 3, '.', '')) ?>"
                       class="w-24 px-2 py-1 rounded border border-stone-300 text-right" />
            <?php else: ?>
                <?= e(rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.')) ?>
            <?php endif; ?>
        </td>

        <!-- kol. 3: cena netto -->
        <td class="px-2 py-1.5 text-right">
            <?php if ($canEditLocal && empty($g['checkout_completed'])): ?>
                <input form="<?= $formId ?>" type="number" name="unit_price" step="0.01" min="0"
                       value="<?= e(number_format($priceN, 2, '.', '')) ?>"
                       class="w-28 px-2 py-1 rounded border border-stone-300 text-right" title="Cena NETTO" />
            <?php else: ?>
                <?= zl($priceN) ?>
            <?php endif; ?>
        </td>

        <!-- kol. 4: VAT -->
        <td class="px-2 py-1.5 text-right">
            <?php if ($canEditLocal && empty($g['checkout_completed'])): ?>
                <div class="flex items-center justify-end gap-1">
                    <input form="<?= $formId ?>" type="number" name="vat_rate" step="0.1" min="0"
                           value="<?= e(number_format($vat, 1, '.', '')) ?>"
                           class="w-20 px-2 py-1 rounded border border-stone-300 text-right" title="VAT %" />
                    <span class="text-stone-500">%</span>
                </div>
            <?php else: ?>
                <?= e(number_format($vat, 1, ',', ' ')) ?>%
            <?php endif; ?>
        </td>

        <!-- kol. 5: wartość netto -->
        <td class="px-2 py-1.5 text-right"><?= zl($rowNet) ?></td>

        <!-- kol. 6: akcje -->
        <td class="px-2 py-1.5 text-right whitespace-nowrap">
            <span data-role="prepared-chip"
                  class="text-xs px-2 py-0.5 rounded-full <?= $done ? 'bg-green-100 border border-green-200 text-green-800' : 'bg-yellow-50 border border-yellow-200 text-yellow-800' ?>">
                <?= $done ? 'przygotowane' : 'w toku' ?>
            </span>

            <?php if ($canEditLocal && empty($g['checkout_completed'])): ?>
                <button form="<?= $formId ?>" class="ml-2 px-2 py-1 rounded border border-stone-300 hover:bg-stone-100">
                    Zapisz
                </button>

                <form class="inline" method="post" action="/admin/orders/api/item_remove.php"
                      onsubmit="return confirm('Usunąć pozycję?')">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                    <input type="hidden" name="group_id" value="<?= $gid ?>">
                    <input type="hidden" name="item_id" value="<?= $iid ?>">
                    <button class="ml-2 px-2 py-1 rounded border border-red-300 text-red-700 hover:bg-red-50">
                        Usuń
                    </button>
                </form>
            <?php endif; ?>
        </td>
    </tr>

    <?php if ($canEditLocal && empty($g['checkout_completed'])): ?>
    <!-- oddzielny (ukryty) form do update, żeby nie zagnieżdżać -->
    <form id="<?= $formId ?>" method="post" action="/admin/orders/api/item_update.php" class="hidden">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
        <input type="hidden" name="group_id" value="<?= $gid ?>">
        <input type="hidden" name="item_id" value="<?= $iid ?>">
    </form>
    <?php endif; ?>

<?php endforeach; ?>
                                </tbody>

                                <tfoot>
                                    <tr class="border-t bg-stone-50">
                                        <td colspan="4" class="px-2 py-2 text-right font-medium">Suma grupy (netto)</td>
                                        <td class="px-2 py-2 text-right font-semibold"><?= zl($sum) ?></td>
                                        <td class="px-2 py-2"></td>
                                    </tr>
                                </tfoot>
                            </table>

                            <?php if ($canEditLocal && empty($g['checkout_completed'])): ?>
                                <!-- Dodaj pozycję -->
                                <div class="mt-3">
                                    <button type="button"
                                        class="px-3 py-1.5 rounded border border-stone-300 hover:bg-stone-100 text-sm"
                                        data-add-toggle="add-<?= $gid ?>">➕ Dodaj pozycję</button>
                                    <div id="add-<?= $gid ?>" class="hidden mt-2">
                                        <form method="post" action="/admin/orders/api/item_add.php" class="grid grid-cols-1 md:grid-cols-6 gap-2">
                                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                                            <input type="hidden" name="group_id" value="<?= $gid ?>">

                                            <input type="text" name="name" placeholder="Nazwa *" required class="px-2 py-1 rounded border border-stone-300 md:col-span-2">
                                            <input type="text" name="code" placeholder="SKU/EAN" class="px-2 py-1 rounded border border-stone-300">
                                            <input type="number" name="qty" placeholder="Ilość *" step="0.001" min="0.001" required class="px-2 py-1 rounded border border-stone-300">
                                            <input type="number" name="unit_price" placeholder="Cena NETTO *" step="0.01" min="0" required class="px-2 py-1 rounded border border-stone-300">
                                            <input type="number" name="vat_rate" placeholder="VAT %" step="0.1" min="0" value="23.0" class="px-2 py-1 rounded border border-stone-300">

                                            <div class="md:col-span-6 flex items-center gap-2">
                                                <button class="px-3 py-1.5 rounded bg-stone-900 text-white hover:bg-stone-800">Dodaj</button>
                                                <span class="text-xs text-stone-500">`source_type` = <b>manual</b>.</span>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-action="toggle-packed"]');
  if (!btn) return;

  const itemId  = btn.dataset.itemId;
  const orderId = btn.dataset.orderId;
  const groupId = btn.dataset.groupId;
  const csrf    = btn.dataset.csrf;

  btn.disabled = true;

  try {
    const res = await fetch('/admin/orders/api/item_toggle_packed.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: new URLSearchParams({ csrf, item_id: itemId, order_id: orderId, group_id: groupId })
    });

    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'Toggle failed');

    // aktualizacja badge
    const prepared = !!json.is_prepared;
    btn.textContent = prepared ? 'spakowane' : 'do spakowania';
    btn.className = 'ml-2 text-xs px-2 py-0.5 rounded-full border ' + (
      prepared ? 'bg-green-100 border-green-200 text-green-800' : 'bg-yellow-50 border-yellow-200 text-yellow-800'
    );

    // progres "X / qty"
    const row = btn.closest('tr');
    const prog = row?.querySelector('[data-role="prepared-progress"]');
    if (prog) prog.textContent = `${json.packed_count} / ${json.qty}`;

    // chip „w toku/przygotowane”
    const chip = row?.querySelector('[data-role="prepared-chip"]');
    if (chip) {
      chip.textContent = prepared ? 'przygotowane' : 'w toku';
      chip.className = 'text-xs px-2 py-0.5 rounded-full ' + (
        prepared ? 'bg-green-100 border border-green-200 text-green-800'
                 : 'bg-yellow-50 border border-yellow-200 text-yellow-800'
      );
    }
  } catch (err) {
    alert('Nie udało się zmienić statusu pakowania: ' + err.message);
  } finally {
    btn.disabled = false;
  }
});
</script>
