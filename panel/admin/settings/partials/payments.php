<?php
// partials/payments.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['section'] ?? '') === 'payments') {
    set_setting($owner_id, 'payments_enabled', isset($_POST['payments_enabled']) ? 'true' : 'false');
    set_setting($owner_id, 'payment_cod_requires_approval', isset($_POST['payment_cod_requires_approval']) ? 'true' : 'false');
    set_setting($owner_id, 'payment_gateway', $_POST['payment_gateway'] ?? 'none');
    set_setting($owner_id, 'payment_gateway_key', $_POST['payment_gateway_key'] ?? '');
    set_setting($owner_id, 'payment_gateway_callback', $_POST['payment_gateway_callback'] ?? '');

    $_SESSION['success_message'] = "Zapisano ustawienia płatności.";
    header("Location: index.php?tab=payments");
    exit;
}

$payments_enabled = get_setting($owner_id, 'payments_enabled') === 'true';
$cod_requires_approval = get_setting($owner_id, 'payment_cod_requires_approval') === 'true';
$gateway = get_setting($owner_id, 'payment_gateway') ?? 'none';
$gateway_key = get_setting($owner_id, 'payment_gateway_key') ?? '';
$callback = get_setting($owner_id, 'payment_gateway_callback') ?? '';
?>

<form method="post" class="space-y-6 max-w-xl">
  <input type="hidden" name="section" value="payments">

  <div class="flex items-center">
    <input type="checkbox" name="payments_enabled" id="payments_enabled" class="mr-2" <?= $payments_enabled ? 'checked' : '' ?>>
    <label for="payments_enabled">Włącz moduł płatności</label>
  </div>

  <div class="flex items-center">
    <input type="checkbox" name="payment_cod_requires_approval" id="payment_cod_requires_approval" class="mr-2" <?= $cod_requires_approval ? 'checked' : '' ?>>
    <label for="payment_cod_requires_approval">Za pobraniem wymaga zatwierdzenia</label>
  </div>

  <div>
    <label class="block font-medium mb-1">Operator płatności (gateway):</label>
    <select name="payment_gateway" class="w-full border px-3 py-2 rounded">
      <option value="none" <?= $gateway === 'none' ? 'selected' : '' ?>>Brak</option>
      <option value="payu" <?= $gateway === 'payu' ? 'selected' : '' ?>>PayU</option>
      <option value="przelewy24" <?= $gateway === 'przelewy24' ? 'selected' : '' ?>>Przelewy24</option>
      <option value="stripe" <?= $gateway === 'stripe' ? 'selected' : '' ?>>Stripe</option>
    </select>
  </div>

  <div>
    <label class="block font-medium mb-1">Klucz API:</label>
    <input type="text" name="payment_gateway_key" value="<?= htmlspecialchars($gateway_key) ?>" class="w-full border px-3 py-2 rounded">
  </div>

  <div>
    <label class="block font-medium mb-1">Callback URL (webhook):</label>
    <input type="text" name="payment_gateway_callback" value="<?= htmlspecialchars($callback) ?>" class="w-full border px-3 py-2 rounded">
  </div>

  <div class="flex justify-end">
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Zapisz</button>
  </div>
</form>
