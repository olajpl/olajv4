<?php
/** expects: $stream, $stream_id, $owner_id */
?>
<div class="flex items-center justify-between">
  <h1 class="text-2xl font-bold">🎥 <?= htmlspecialchars($stream['title'] ?? 'Transmisja') ?></h1>
  <button
    id="btnFinalize"
    data-live-id="<?= (int)$stream_id ?>"
    class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 disabled:opacity-60">
    Wyślij podsumowania
  </button>
</div>
