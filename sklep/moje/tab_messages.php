<?php
// moje/tab_messages.php – Zakładka "Wiadomości"
$client_id = $client['id'];

$stmt = $pdo->prepare("SELECT * FROM messages WHERE client_id = :client_id ORDER BY created_at ASC");
$stmt->execute(['client_id' => $client_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$messages) {
    echo "<p class='text-gray-500'>Brak wiadomości od klienta.</p>";
    return;
}

$platform_icons = [
    'facebook' => '🟦 Facebook',
    'chat' => '💬 Chat',
    'mobile' => '📱 Mobile',
    'other' => '❔ Inne'
];
?>

<div class="space-y-4">
  <?php foreach ($messages as $msg): ?>
    <div class="bg-white p-3 rounded-lg shadow text-sm">
      <div class="flex justify-between items-center mb-1">
        <span class="text-gray-700 font-medium">
          <?= $platform_icons[$msg['platform']] ?? '❔' ?>
        </span>
        <span class="text-gray-500 text-xs">
          <?= date('d.m.Y H:i', strtotime($msg['created_at'])) ?>
        </span>
      </div>
      <div class="text-gray-800 whitespace-pre-line">
        <?= htmlspecialchars($msg['message']) ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>