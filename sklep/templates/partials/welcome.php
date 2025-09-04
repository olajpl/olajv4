<?php if (!empty($welcomeMsg)): ?>
    <div class="bg-pink-50 text-pink-800 text-sm text-center py-2">
        <?= nl2br(htmlspecialchars($welcomeMsg)) ?>
    </div>
<?php endif; ?>