<?php
function renderIcon(string $name): string
{
    return '<i data-lucide="' . htmlspecialchars($name) . '" class="w-5 h-5 transition-transform duration-300 ease-in-out group-hover:scale-110"></i>';
}
?>

<ul class="space-y-1 pb-4 text-sm text-gray-200" x-data="{ openMenu: '' }">
    <?php foreach ($items as $item): ?>
        <?php
        $isActive = isset($item['link']) && str_starts_with($_SERVER['REQUEST_URI'] ?? '', $item['link']);
        $iconName = $item['icon'] ?? 'circle';
        $iconHtml = renderIcon($iconName);
        ?>
        <?php if (isset($item['children'])): ?>
            <li x-data="{ open: false }" class="pt-1">
                <button type="button"
                    class="group flex items-center justify-between w-full px-2 py-2 text-left text-gray-100 hover:bg-gray-800 rounded"
                    @click="open = !open">
                    <span class="flex items-center gap-2"><?= $iconHtml ?> <?= htmlspecialchars($item['label']) ?></span>
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
                                <?= htmlspecialchars($child['label']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        <?php else: ?>
            <li>
                <a href="<?= $item['link'] ?>"
                    class="group flex items-center gap-2 px-2 py-2 rounded <?= $isActive ? 'bg-gray-700 text-white font-bold' : 'hover:bg-gray-800' ?>">
                    <?= $iconHtml ?> <?= htmlspecialchars($item['label']) ?>
                </a>
            </li>
        <?php endif; ?>
    <?php endforeach; ?>
</ul>