<?php
$initialCount = (int)$miniQ;
$freeShipShow = ($FREE_SHIP['threshold'] ?? 0) > 0;
$userHref = $clientTokenLink ? ('/moje.php?token=' . urlencode($clientTokenLink)) : '/konto/recover.php';
$initial = $clientName ? mb_strtoupper(mb_substr($clientName, 0, 1)) : '👤';
?>
<header class="bg-white shadow-sm sticky top-0 z-50">
    <div class="max-w-6xl mx-auto px-4 py-2 flex items-center justify-between relative">
        <div class="flex items-center gap-3">
            <?php if (!empty($logoPath)): ?>
                <img src="<?= htmlspecialchars($logoPath) ?>" alt="logo" class="h-8">
            <?php else: ?>
                <span class="text-lg font-bold" style="color: var(--theme-color)">olaj.pl</span>
            <?php endif; ?>
        </div>

        <div id="freeShipBar" class="<?= $freeShipShow ? 'flex' : 'hidden' ?> md:flex items-center gap-2 absolute left-1/2 -translate-x-1/2" aria-live="polite">
            <div class="h-2 w-40 bg-gray-200 rounded overflow-hidden" aria-hidden="true">
                <div id="freeShipFill" class="h-2" style="background: var(--theme-color); width: <?= (float)$FREE_SHIP['progress_pct'] ?>%"></div>
            </div>
            <span id="freeShipText" class="text-xs text-gray-600"><?= htmlspecialchars($FREE_SHIP['text'] ?? '') ?></span>
        </div>

        <div class="flex items-center gap-4">
            <a href="javascript:void(0)" onclick="toggleMiniCart(true)" class="cart-button relative" aria-label="Koszyk">
                <div id="lottie-cart" class="h-8 w-8" style="width:32px;height:32px;position:relative;"></div>
                <svg id="fallback-cart" xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 absolute top-0 right-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" style="color: var(--theme-color)">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13l-1.35 2.7a1 1 0 00.9 1.5h12.9M7 13L5.4 5M16 21a1 1 0 100-2 1 1 0 000 2zm-8 0a1 1 0 100-2 1 1 0 000 2z" />
                </svg>
                <span id="cart-count" class="cart-count <?= $initialCount > 0 ? '' : 'hidden' ?>"><?= $initialCount ?: '' ?></span>
            </a>

            <a href="<?= htmlspecialchars($userHref) ?>" class="relative" aria-label="Moje konto">
                <div class="w-8 h-8 rounded-full text-white grid place-items-center font-bold" style="background: var(--theme-color)"><?= htmlspecialchars($initial) ?></div>
            </a>
        </div>
    </div>
    <div class="border-t border-gray-200 mt-1">
        <div class="flex justify-center gap-4 text-sm py-2">
            <a href="#" class="text-gray-700 hover:underline">🍭 Sklep</a>
            <a href="#" class="text-gray-700 hover:underline">📻 Transmisje</a>
            <a href="?theme=default" class="text-gray-700 hover:underline">🎨 Domyślny</a>
            <a href="?theme=cyberpunk" class="text-gray-700 hover:underline">🟦 Cyberpunk</a>
            <a href="?theme=love_pink" class="text-gray-700 hover:underline">💗 Love Pink</a>
        </div>
    </div>
</header>