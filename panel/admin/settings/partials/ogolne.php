<?php
// partials/ogolne.php
$keys = [
    'company_name' => 'Nazwa firmy',
    'contact_email' => 'Email kontaktowy',
    'dark_mode_enabled' => 'Tryb ciemny (1=tak)',
    'client_token_prefix' => 'Prefiks tokena klienta'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['section'] ?? '') === 'ogolne') {
    foreach ($keys as $key => $label) {
        $value = trim($_POST[$key] ?? '');
        set_setting($owner_id, $key, $value);
    }
    $_SESSION['success_message'] = 'Ustawienia zostały zapisane.';
    header("Location: index.php?tab=ogolne");
    exit;
}

$values = [];
foreach ($keys as $key => $_) {
    $values[$key] = get_setting($owner_id, $key);
}
?>

<form method="POST" class="space-y-4 max-w-xl">
  <input type="hidden" name="section" value="ogolne">
  <?php foreach ($keys as $key => $label): ?>
    <div>
      <label class="block font-medium mb-1"><?= $label ?>:</label>
      <input type="text" name="<?= $key ?>" value="<?= htmlspecialchars($values[$key]) ?>" class="w-full border px-3 py-2 rounded">
    </div>
  <?php endforeach; ?>
  <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">📂 Zapisz</button>
</form>