<?php
// partials/integrations.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['section'] ?? '') === 'integrations') {
    set_setting($owner_id, 'facebook_app_id', $_POST['facebook_app_id'] ?? '');
    set_setting($owner_id, 'facebook_page_token', $_POST['facebook_page_token'] ?? '');
    set_setting($owner_id, 'furgonetka_api_key', $_POST['furgonetka_api_key'] ?? '');
    set_setting($owner_id, 'apaczka_api_key', $_POST['apaczka_api_key'] ?? '');

    $_SESSION['success_message'] = "Zapisano dane integracji.";
    header("Location: index.php?tab=integrations");
    exit;
}

$facebook_app_id = get_setting($owner_id, 'facebook_app_id') ?? '';
$facebook_page_token = get_setting($owner_id, 'facebook_page_token') ?? '';
$furgonetka_key = get_setting($owner_id, 'furgonetka_api_key') ?? '';
apaczka_key = get_setting($owner_id, 'apaczka_api_key') ?? '';
?>

<form method="post" class="space-y-6 max-w-xl">
  <input type="hidden" name="section" value="integrations">

  <div>
    <label class="block font-medium mb-1">Facebook App ID:</label>
    <input type="text" name="facebook_app_id" value="<?= htmlspecialchars($facebook_app_id) ?>" class="w-full border px-3 py-2 rounded">
  </div>

  <div>
    <label class="block font-medium mb-1">Facebook Page Token:</label>
    <input type="text" name="facebook_page_token" value="<?= htmlspecialchars($facebook_page_token) ?>" class="w-full border px-3 py-2 rounded">
  </div>

  <div>
    <label class="block font-medium mb-1">Furgonetka API Key:</label>
    <input type="text" name="furgonetka_api_key" value="<?= htmlspecialchars($furgonetka_key) ?>" class="w-full border px-3 py-2 rounded">
  </div>

  <div>
    <label class="block font-medium mb-1">Apaczka API Key:</label>
    <input type="text" name="apaczka_api_key" value="<?= htmlspecialchars($apaczka_key) ?>" class="w-full border px-3 py-2 rounded">
  </div>

  <div class="flex justify-end">
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Zapisz</button>
  </div>
</form>
