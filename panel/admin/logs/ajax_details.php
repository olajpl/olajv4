<?php
// admin/logs/ajax_details.php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: text/html; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('<div class="p-4">❌ Brak ID.</div>');
}

$st = $pdo->prepare("SELECT * FROM logs WHERE id=:id LIMIT 1");
$st->execute([':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
  http_response_code(404);
  exit('<div class="p-4">❌ Nie znaleziono wpisu.</div>');
}

// decode IP (VARBINARY(16)) → tekst
$ipText = null;
if (!is_null($row['ip'])) {
  // PDO zwraca blob jako string binarny → inet_ntop
  $ipText = @inet_ntop($row['ip']);
}

// pretty JSON
$ctxPretty = null;
if (!empty($row['context_json'])) {
  $decoded = json_decode($row['context_json'], true);
  if (json_last_error() === JSON_ERROR_NONE) {
    $ctxPretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  }
}

?>
<div class="max-h-[85vh] overflow-y-auto rounded-xl">
  <div class="flex items-center justify-between px-5 py-4 border-b">
    <h3 class="text-lg font-semibold">Log #<?= (int)$row['id'] ?></h3>
    <button class="px-3 py-1 text-sm bg-gray-100 rounded hover:bg-gray-200" onclick="this.closest('.fixed')?.remove()">Zamknij</button>
  </div>
  <div class="p-5 space-y-4">
    <div class="grid md:grid-cols-2 gap-4 text-sm">
      <div><b>czas</b><br><?= htmlspecialchars($row['created_at']) ?></div>
      <div><b>level</b><br><?= htmlspecialchars($row['level']) ?></div>
      <div><b>channel</b><br><?= htmlspecialchars($row['channel']) ?></div>
      <div><b>event</b><br><?= htmlspecialchars((string)$row['event']) ?></div>
      <div><b>owner_id</b><br><?= htmlspecialchars((string)$row['owner_id']) ?></div>
      <div><b>request_id</b><br><span class="font-mono"><?= htmlspecialchars((string)$row['request_id']) ?></span></div>
      <div><b>order_id</b><br><?= htmlspecialchars((string)$row['order_id']) ?></div>
      <div><b>order_group_id</b><br><?= htmlspecialchars((string)$row['order_group_id']) ?></div>
      <div><b>client_id</b><br><?= htmlspecialchars((string)$row['client_id']) ?></div>
      <div><b>live_id</b><br><?= htmlspecialchars((string)$row['live_id']) ?></div>
      <div><b>source</b><br><?= htmlspecialchars((string)$row['source']) ?></div>
      <div><b>ip</b><br><?= htmlspecialchars((string)$ipText) ?></div>
      <div class="md:col-span-2"><b>user_agent</b><br><span class="break-all"><?= htmlspecialchars((string)$row['user_agent']) ?></span></div>
      <div class="md:col-span-2"><b>flags</b><br><?= htmlspecialchars((string)$row['flags']) ?></div>
      <div class="md:col-span-2"><b>message</b><br>
        <div class="p-2 bg-gray-50 rounded"><?= nl2br(htmlspecialchars((string)$row['message'])) ?></div>
      </div>
    </div>

    <div>
      <b>context_json</b>
      <pre class="p-3 bg-gray-900 text-gray-100 rounded text-xs overflow-x-auto"><?= htmlspecialchars($ctxPretty ?? (string)$row['context_json']) ?></pre>
    </div>

    <?php if (!empty($row['trace'])): ?>
      <div>
        <b>trace</b>
        <pre class="p-3 bg-gray-900 text-gray-100 rounded text-xs overflow-x-auto"><?= htmlspecialchars((string)$row['trace']) ?></pre>
      </div>
    <?php endif; ?>
  </div>
</div>