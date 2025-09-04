<?php
$initialCount = (int)$miniQ;
$miniTotalV = number_format($miniTotal, 2, ',', ' ') . ' ' . htmlspecialchars($currency);
?>
<aside id="miniCart" class="fixed top-0 right-0 w-80 max-w-[90vw] h-full bg-white shadow-2xl transition-transform z-50 flex flex-col" aria-labelledby="miniCartTitle" role="dialog" style="transform: translateX(100%)">
    <div class="p-4 flex justify-between items-center border-b">
        <strong id="miniCartTitle">Twój koszyk</strong>
        <button onclick="toggleMiniCart(false)" aria-label="Zamknij koszyk">✖</button>
    </div>

    <div id="miniCartBody" class="p-4 space-y-3 overflow-auto flex-1">
        <?php if (empty($miniItems)): ?>
            <div class="text-gray-500">Koszyk pusty</div>
        <?php else: ?>
            <?php foreach ($miniItems as $it): $line = (float)$it['price'] * (float)$it['qty']; ?>
                <div class="flex items-center gap-3 group" data-pid="<?= (int)$it['id'] ?>">
                    <?php $thumb = !empty($it['image_path']) ? ($cdn . '/uploads/products/' . ltrim($it['image_path'], '/')) : 'https://via.placeholder.com/64?text=%20'; ?>
                    <img src="<?= $thumb ?>" alt="" class="w-12 h-12 object-cover rounded" loading="lazy">
                    <div class="flex-1">
                        <div class="font-medium line-clamp-1"><?= htmlspecialchars($it['name'] ?? 'Produkt') ?></div>
                        <div class="text-xs text-gray-500"><?= number_format((float)$it['price'], 2, ',', ' ') ?> <?= htmlspecialchars($currency) ?> × <?= (int)$it['qty'] ?></div>
                        <div class="mt-1 flex items-center gap-2">
                            <button class="px-2 py-1 border rounded" data-op="dec" aria-label="Zmniejsz">−</button>
                            <input class="w-12 text-center border rounded py-1" type="number" min="0" value="<?= (int)$it['qty'] ?>" data-role="qty" aria-label="Ilość pozycji">
                            <button class="px-2 py-1 border rounded" data-op="inc" aria-label="Zwiększ">+</button>
                            <button class="ml-auto text-gray-400 hover:text-red-600" data-op="remove" title="Usuń" aria-label="Usuń">🗑</button>
                        </div>
                    </div>
                    <div class="text-sm font-semibold min-w-[72px] text-right"><?= number_format($line, 2, ',', ' ') ?> <?= htmlspecialchars($currency) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="p-4 border-t space-y-3">
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Suma</span>
            <span id="miniSum" class="font-semibold"><?= $miniTotalV ?></span>
        </div>
        <div id="miniCta"></div>
    </div>
</aside>