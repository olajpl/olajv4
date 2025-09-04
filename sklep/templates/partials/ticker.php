<?php if (!empty($marqueeItems)): ?>
    <div class="bg-white shadow-md z-40 relative">
        <div class="overflow-hidden whitespace-nowrap">
            <div id="tickerTrack" class="inline-flex gap-8 py-2 px-4">
                <?php foreach ($marqueeItems as $msg): ?>
                    <span class="text-sm text-gray-700"><?= htmlspecialchars(trim((string)$msg)) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>