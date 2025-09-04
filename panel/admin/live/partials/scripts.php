<?php
/** expects: $owner_id, $stream_id, $stream */
?>
<script>
  window.OLAJ_LIVE = {
    ownerId: <?= (int)$owner_id ?>,
    liveId:  <?= (int)$stream_id ?>,
    status:  "<?= htmlspecialchars($stream['status'] ?? 'planned') ?>"
  };
</script>
<!-- Select2 i własny skrypt — zakładam, że Select2 masz już globalnie -->
<script src="js/live_view.js?v=<?= time() ?>"></script>
