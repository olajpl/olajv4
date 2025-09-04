<?php

/**
 * Wymaga: $groups, $itemsByGroup, $dues, $paid, $applied, $order, $csrf, $canEditLocal
 * Helpery: e(), zl()
 */
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
                foreach ($groupItems as $it) $sum += (float)$it['qty'] * (float)($it['unit_price'] ?? 0.0);
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
                            <table class="w-full text-sm">
                                <thead class="bg-stone-50">
                                    <tr>
                                        <th class="text-left px-2 py-1.5">Produkt</th>
                                        <th class="text-right px-2 py-1.5">Ilość</th>
                                        <th class="text-right px-2 py-1.5">Cena</th>
                                        <th class="text-right px-2 py-1.5">Wartość</th>
                                        <th class="px-2 py-1.5"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($groupItems as $it):
                                        $rowVal = (float)$it['qty'] * (float)($it['unit_price'] ?? 0.0);
                                        $sku = $it['sku'] ?? null;
                                    ?>
                                        <tr class="border-t" data-item-id="<?= (int)($it['id'] ?? 0) ?>">
                                            <td class="px-2 py-1.5">
                                                <div class="font-medium"><?= e((string)($it['name'] ?? $it['product_name'] ?? 'Produkt')) ?></div>
                                                <?php if (!empty($sku)): ?>
                                                    <div class="text-stone-500 text-xs">sku: <?= e((string)$sku) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-2 py-1.5 text-right">
                                                <?php if ($canEditLocal && empty($g['checkout_completed'])): ?>
                                                    <form class="inline-flex items-center gap-1 justify-end" method="post" action="/admin/orders/api/item_update_qty.php">
                                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                                        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                                                        <input type="hidden" name="group_id" value="<?= $gid ?>">
                                                        <input type="hidden" name="item_id" value="<?= (int)($it['id'] ?? 0) ?>">
                                                        <input type="number" name="qty" step="0.001" min="0" value="<?= e((string)$it['qty']) ?>"
                                                            class="w-24 px-2 py-1 rounded border border-stone-300 text-right" />
                                                        <button class="px-2 py-1 rounded border border-stone-300 hover:bg-stone-100">Zapisz</button>
                                                    </form>
                                                <?php else: ?>
                                                    <?= e((string)($it['qty'] ?? '')) ?>
                                                <?php endif; ?>
                                                <div class="text-xs text-stone-500 mt-0.5">
                                                    <span data-role="prepared-progress">
                                                        <?= (int)($it['packed_count'] ?? 0) ?> / <?= e((string)($it['qty'] ?? '0')) ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-2 py-1.5 text-right"><?= zl((float)($it['unit_price'] ?? 0.0)) ?></td>
                                            <td class="px-2 py-1.5 text-right"><?= zl($rowVal) ?></td>
                                            <td class="px-2 py-1.5 text-right">
                                                <?php $done = ((int)($it['packed_count'] ?? 0)) >= (float)($it['qty'] ?? 0.0); ?>
                                                <span data-role="prepared-chip" class="text-xs px-2 py-0.5 rounded-full <?= $done ? 'bg-green-100 border border-green-200 text-green-800' : 'bg-yellow-50 border border-yellow-200 text-yellow-800' ?>">
                                                    <?= $done ? 'przygotowane' : 'w toku' ?>
                                                </span>
                                                <?php if ($canEditLocal && empty($g['checkout_completed'])): ?>
                                                    <form class="inline" method="post" action="/admin/orders/api/item_remove.php" onsubmit="return confirm('Usunąć pozycję?')">
                                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                                        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                                                        <input type="hidden" name="group_id" value="<?= $gid ?>">
                                                        <input type="hidden" name="item_id" value="<?= (int)($it['id'] ?? 0) ?>">
                                                        <button class="ml-2 px-2 py-1 rounded border border-stone-300 hover:bg-stone-100">Usuń</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t bg-stone-50">
                                        <td colspan="3" class="px-2 py-2 text-right font-medium">Suma grupy</td>
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
                                            <input type="number" name="unit_price" placeholder="Cena brutto *" step="0.01" min="0" required class="px-2 py-1 rounded border border-stone-300">
                                            <input type="number" name="vat_rate" placeholder="VAT %" step="0.01" min="0" value="23.00" class="px-2 py-1 rounded border border-stone-300">

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