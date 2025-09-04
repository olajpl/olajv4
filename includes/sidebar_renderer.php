<?php
function renderIcon(string $icon, string $type = ''): string
{
    $base = 'inline-block w-5 text-lg transition-transform duration-300 ease-in-out';

    $map = [
        'settings'  => 'hover:rotate-45',
        'messages'  => 'hover:animate-pulse',
        'orders'    => 'hover:scale-110',
        'products'  => 'hover:rotate-6 hover:scale-105',
        'clients'   => 'hover:scale-105',
        'live'      => 'hover:animate-bounce',
        'logs'      => 'hover:animate-spin',
        'cw'        => 'hover:animate-wiggle',
        'suppliers' => 'hover:scale-110',
        'home'      => 'hover:rotate-3',
    ];

    return sprintf('<span class="%s %s">%s</span>', $base, $map[$type] ?? '', $icon);
}
?>

<ul class="space-y-1 pb-4 text-sm text-gray-200" x-data="{ openMenu: '' }">
    <?php foreach ($items as $item): ?>
        <?php
        $isActive = isset($item['link']) && str_starts_with($_SERVER['REQUEST_URI'] ?? '', $item['link']);
        $icon = $item['icon'] ?? '•';
        ?>
        <?php if (isset($item['children'])): ?>
            <li x-data="{ open: false }" class="pt-1">
                <button type="button"
                    class="flex items-center justify-between w-full px-2 py-2 text-left text-gray-100 hover:bg-gray-800 rounded"
                    @click="open = !open">
                    <span class="flex items-center gap-2"><?= renderIcon($icon, $item['type']) ?> <?= htmlspecialchars($item['label']) ?></span>
                    <svg class="w-4 h-4 transform transition-transform" :class="{ 'rotate-90': open }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>
                <ul class="pl-6 mt-1 space-y-0.5" x-show="open" x-transition>
                    <?php foreach ($item['children'] as $child):
                        $childActive = str_starts_with($_SERVER['REQUEST_URI'] ?? '', $child['link']);
                    ?>
                        <li>
                            <a href="<?= $child['link'] ?>"
                                class="block px-2 py-1 rounded <?= $childActive ? 'bg-gray-700 text-white font-bold' : 'hover:bg-gray-800' ?>">
                                <?= $child['label'] ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        <?php else: ?>
            <li>
                <a href="<?= $item['link'] ?>"
                    class="flex items-center gap-2 px-2 py-2 rounded <?= $isActive ? 'bg-gray-700 text-white font-bold' : 'hover:bg-gray-800' ?>">
                    <?= renderIcon($icon, $item['type']) ?> <?= htmlspecialchars($item['label']) ?>
                </a>
            </li>
        <?php endif; ?>
    <?php endforeach; ?>
</ul>