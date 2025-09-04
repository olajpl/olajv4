<?php
// partials/automations.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['section'] ?? '') === 'automations') {
    set_setting($owner_id, 'auto_close_orders_hours', $_POST['auto_close_orders_hours'] ?? '');
    set_setting($owner_id, 'auto_notify_reserved_available', isset($_POST['auto_notify_reserved_available']) ? 'true' : 'false');
    set_setting($owner_id, 'auto_send_daily_summary', isset($_POST['auto_send_daily_summary']) ? 'true' : 'false');

    $_SESSION['success_message'] = "Zapisano ustawienia automatyzacji.";
    header("Location: index.php?tab=automations");
    exit;
}

$auto_close = get_setting($owner_id, 'auto_close_orders_hours') ?? '';
$notify_reserved = get_setting($owner_id, 'auto_notify_reserved_available') === 'true';
$send_summary = get_setting($owner_id, 'auto_send_daily_summary') === 'true';
?>

<form method="post" class="space-y-6 max-w-xl">
  <input type="hidden" name="section" value="automations">

  <div>
    <label class="block font-medium mb-1">Automatycznie zamknij paczkę po (godz.):</label>
    <input type="number" name="auto_close_orders_hours" value="<?= htmlspecialchars($auto_close) ?>" class="w-full border px-3 py-2 rounded">
  </div>

  <div class="flex items-center">
    <input type="checkbox" name="auto_notify_reserved_available" id="auto_notify_reserved_available" class="mr-2" <?= $notify_reserved ? 'checked' : '' ?>>
    <label for="auto_notify_reserved_available">Wysyłaj automatycznie info o dostępnych rezerwacjach</label>
  </div>

  <div class="flex items-center">
    <input type="checkbox" name="auto_send_daily_summary" id="auto_send_daily_summary" class="mr-2" <?= $send_summary ? 'checked' : '' ?>>
    <label for="auto_send_daily_summary">Wysyłaj podsumowanie dnia</label>
  </div>

  <div class="flex justify-end">
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Zapisz</button>
  </div>
</form>
