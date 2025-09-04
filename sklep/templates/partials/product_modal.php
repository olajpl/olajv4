<?php
// templates/partials/product_modal.php
// Kontener na modal produktu (wypełniany dynamicznie w JS)

?>
<div id="product-modal"
    class="hidden fixed inset-0 z-50 bg-black bg-opacity-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="product-modal-title">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full overflow-hidden">
        <div class="flex flex-col md:flex-row gap-4 p-4">
            <div class="flex-shrink-0">
                <img id="pm-image" src="https://via.placeholder.com/300x300?text=Produkt"
                    alt="Podgląd produktu"
                    class="w-40 h-40 md:w-56 md:h-56 object-cover rounded-md">
            </div>
            <div class="flex-1 min-w-0">
                <h2 id="pm-title" class="text-lg font-bold mb-1">Produkt</h2>
                <div id="pm-price" class="text-xl font-semibold text-pink-600 mb-2">0,00 zł</div>
                <div id="pm-meta" class="text-sm text-gray-500 mb-3">Dostępność: —</div>
                <div id="pm-desc" class="text-sm text-gray-800 leading-relaxed mb-4">
                    Opis produktu wczytywany dynamicznie…
                </div>

                <div class="flex items-stretch gap-2">
                    <div class="flex items-center border rounded-lg">
                        <button class="px-2" id="pm-dec">−</button>
                        <input type="number" id="pm-qty" min="1" value="1" class="w-14 text-center border-0">
                        <button class="px-2" id="pm-inc">+</button>
                    </div>
                    <button id="pm-add"
                        class="flex-1 py-2 text-white rounded-lg"
                        style="background: var(--theme-color)">➕ Dodaj</button>
                </div>
            </div>
            <button id="pm-close"
                class="absolute top-3 right-3 text-gray-500 hover:text-gray-800"
                aria-label="Zamknij modal">✖</button>
        </div>
    </div>
</div>