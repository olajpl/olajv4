<?php
// partials/shipping.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['section'] ?? '') === 'shipping') {
    set_setting($owner_id, 'shipping_enabled', isset($_POST['shipping_enabled']) ? 'true' : 'false');
    set_setting($owner_id, 'max_package_weight', $_POST['max_package_weight'] ?? '');
    $_SESSION['success_message'] = "Zapisano ustawienia wysyłki.";
    header("Location: index.php?tab=shipping");
    exit;
}

$shipping_enabled = get_setting($owner_id, 'shipping_enabled') === 'true';
$max_weight = get_setting($owner_id, 'max_package_weight') ?? '';
?>

<form method="post" class="space-y-6 max-w-xl">
  <input type="hidden" name="section" value="shipping">

  <div class="flex items-center">
    <input type="checkbox" name="shipping_enabled" id="shipping_enabled" class="mr-2" <?= $shipping_enabled ? 'checked' : '' ?>>
    <label for="shipping_enabled">Włącz moduł wysyłki</label>
  </div>

  <div>
    <label class="block font-medium mb-1">Maksymalna waga paczki (kg):</label>
    <input type="number" step="0.01" name="max_package_weight" value="<?= htmlspecialchars($max_weight) ?>" class="w-full border px-3 py-2 rounded">
  </div>

  <div class="flex justify-end">
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Zapisz</button>
  </div>
</form>