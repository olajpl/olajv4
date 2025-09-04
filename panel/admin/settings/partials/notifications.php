<?php
// partials/notifications.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['section'] ?? '') === 'notifications') {
    set_setting($owner_id, 'notify_email_enabled', isset($_POST['notify_email_enabled']) ? 'true' : 'false');
    set_setting($owner_id, 'notify_push_enabled', isset($_POST['notify_push_enabled']) ? 'true' : 'false');
    set_setting($owner_id, 'notify_sound_enabled', isset($_POST['notify_sound_enabled']) ? 'true' : 'false');
    set_setting($owner_id, 'admin_notification_email', $_POST['admin_notification_email'] ?? '');

    $_SESSION['success_message'] = "Zapisano ustawienia powiadomień.";
    header("Location: index.php?tab=notifications");
    exit;
}

$notify_email = get_setting($owner_id, 'notify_email_enabled') === 'true';
$notify_push = get_setting($owner_id, 'notify_push_enabled') === 'true';
$notify_sound = get_setting($owner_id, 'notify_sound_enabled') === 'true';
$admin_email = get_setting($owner_id, 'admin_notification_email') ?? '';
?>

<form method="post" class="space-y-6 max-w-xl">
  <input type="hidden" name="section" value="notifications">

  <div class="flex items-center">
    <input type="checkbox" name="notify_email_enabled" id="notify_email_enabled" class="mr-2" <?= $notify_email ? 'checked' : '' ?>>
    <label for="notify_email_enabled">Wysyłaj powiadomienia e-mail</label>
  </div>

  <div class="flex items-center">
    <input type="checkbox" name="notify_push_enabled" id="notify_push_enabled" class="mr-2" <?= $notify_push ? 'checked' : '' ?>>
    <label for="notify_push_enabled">Wysyłaj powiadomienia push</label>
  </div>

  <div class="flex items-center">
    <input type="checkbox" name="notify_sound_enabled" id="notify_sound_enabled" class="mr-2" <?= $notify_sound ? 'checked' : '' ?>>
    <label for="notify_sound_enabled">Włącz powiadomienia dźwiękowe</label>
  </div>

  <div>
    <label class="block font-medium mb-1">E-mail administratora do powiadomień:</label>
    <input type="email" name="admin_notification_email" value="<?= htmlspecialchars($admin_email) ?>" class="w-full border px-3 py-2 rounded">
  </div>

  <div class="flex justify-end">
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Zapisz</button>
  </div>
</form>
