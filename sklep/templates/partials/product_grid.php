<main class="max-w-6xl mx-auto px-4 py-6">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <?php foreach ($products as $p): ?>
            <?php
            $id     = (int)($p['id'] ?? 0);
            $name   = $p['name'] ?? 'Produkt';
            $price  = (float)($p['price'] ?? 0);
            $priceV = number_format($price, 2, ',', ' ');
            $weight = isset($p['weight_kg']) ? (float)$p['weight_kg'] : null;
            $stock  = (float)($p['stock'] ?? 0);
            $imgRel = $p['image_path'] ?? '';
            $img    = $imgRel ? ($cdn . '/uploads/products/' . ltrim($imgRel, '/')) : 'https://via.placeholder.com/400x300?text=Brak+zdjęcia';
            ?>
            <div class="product-card p-3 rounded-xl shadow-sm relative bg-white"
                data-pid="<?= $id ?>"
                data-name="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                data-price="<?= $price ?>"
                data-currency="<?= htmlspecialchars($currency, ENT_QUOTES) ?>"
                data-weight="<?= $weight !== null ? number_format($weight, 2, ',', ' ') : '' ?>"
                data-stock="<?= $stock ?>"
                data-img="<?= htmlspecialchars($img, ENT_QUOTES) ?>">

                <button class="block w-full text-left" onclick="openProductCardFromCard(this)" aria-label="Pokaż kartę produktu">
                    <img src="<?= $img ?>" loading="lazy" alt="<?= htmlspecialchars($name) ?>" class="w-full h-40 object-cover rounded-md mb-2">
                    <h2 class="text-base font-semibold line-clamp-2 mb-1 hover:opacity-80"><?= htmlspecialchars($name) ?></h2>
                </button>

                <div class="flex justify-between text-sm text-gray-700 mb-1">
                    <div class="font-bold text-base" style="color: var(--theme-color)"><?= $priceV ?> <?= htmlspecialchars($currency) ?></div>
                    <?php if (!empty($showWeight) && $weight !== null): ?>
                        <div class="text-xs text-gray-500 mt-1">Waga: <?= number_format($weight, 2, ',', ' ') ?> kg</div>
                    <?php endif; ?>
                </div>

                <div class="flex items-stretch gap-2 mt-2">
                    <div class="flex items-center border rounded-lg" role="group" aria-label="Zmiana ilości">
                        <button class="px-2" aria-label="Zmniejsz ilość" onclick="stepQty(this,-1)">−</button>
                        <input type="number" min="1" value="1" class="w-12 text-center border-0" aria-label="Ilość" />
                        <button class="px-2" aria-label="Zwiększ ilość" onclick="stepQty(this,1)">+</button>
                    </div>
                    <?php if ($stock > 0): ?>
                        <button onclick="addToCartInline(<?= $id ?>, this)"
                            class="flex-1 btn-main hover:opacity-90 text-white py-2 text-sm rounded-lg transition"
                            style="background: var(--theme-color)"
                            aria-label="Dodaj produkt do koszyka">
                            ➕ Dodaj
                        </button>
                    <?php else: ?>
                        <button onclick="reserveProduct(<?= $id ?>)" class="flex-1 bg-yellow-500 text-white py-2 text-sm rounded-lg transition">📦 Zarezerwuj</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>