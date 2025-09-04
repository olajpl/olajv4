<?php
// admin/settings/integracje/index.php — Integracje systemowe (V4, safe)
declare(strict_types=1);

session_start();
if (empty($_SESSION['user']) || !isset($_SESSION['user']['id'])) {
  $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/admin/settings/integracje/');
  header("Location: /auth/login.php?redirect={$redirect}");
  exit;
}

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/settings.php';
require_once __DIR__ . '/../../../layout/layout_header.php';


$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($owner_id <= 0) {
  http_response_code(401);
  echo "<div class='p-6'>Brak uprawnień.</div>";
  require_once __DIR__ . '/../../../layout/layout_footer.php';
  exit;
}

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// GET aktualne wartości
$facebook_page_id       = (string)get_setting($owner_id, 'facebook_page_id');
$facebook_token         = (string)get_setting($owner_id, 'facebook_token');
$furgonetka_api_key     = (string)get_setting($owner_id, 'furgonetka_api_key');
$furgonetka_sender_name = (string)get_setting($owner_id, 'furgonetka_sender_name');
$furgonetka_sender_phone = (string)get_setting($owner_id, 'furgonetka_sender_phone');

$ERRORS = [];

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf'])) {
      throw new RuntimeException('Błędny token CSRF. Odśwież stronę i spróbuj ponownie.');
    }

    $facebook_page_id_new        = trim((string)($_POST['facebook_page_id'] ?? ''));
    $facebook_token_new          = trim((string)($_POST['facebook_token'] ?? ''));
    $furgonetka_api_key_new      = trim((string)($_POST['furgonetka_api_key'] ?? ''));
    $furgonetka_sender_name_new  = trim((string)($_POST['furgonetka_sender_name'] ?? ''));
    $furgonetka_sender_phone_new = trim((string)($_POST['furgonetka_sender_phone'] ?? ''));

    // proste walidacje
    if ($furgonetka_sender_phone_new && !preg_match('/^[0-9+\-\s]{5,20}$/', $furgonetka_sender_phone_new)) {
      $ERRORS[] = "Numer telefonu nadawcy wygląda niepoprawnie.";
    }

    if (!$ERRORS) {
      set_setting($owner_id, 'facebook_page_id',        $facebook_page_id_new);
      set_setting($owner_id, 'facebook_token',          $facebook_token_new);
      set_setting($owner_id, 'furgonetka_api_key',      $furgonetka_api_key_new);
      set_setting($owner_id, 'furgonetka_sender_name',  $furgonetka_sender_name_new);
      set_setting($owner_id, 'furgonetka_sender_phone', $furgonetka_sender_phone_new);

      $_SESSION['success_message'] = "Integracje zapisane.";
      header("Location: index.php");
      exit;
    }
  } catch (Throwable $e) {
    $ERRORS[] = "Błąd zapisu: " . $e->getMessage();
  }
}
?>

<div class="container mx-auto p-4">
  <div class="flex items-center justify-between mb-4">
    <a href="../" class="text-sm text-blue-600 hover:underline flex items-center gap-1">
      <span class="text-lg">←</span> Wróć do ustawień
    </a>
    <h1 class="text-2xl font-bold">Integracje</h1>
  </div>

  <?php if ($ERRORS): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-2 rounded mb-4">
      <ul class="list-disc ml-5"><?php foreach ($ERRORS as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="bg-green-100 text-green-800 p-3 rounded mb-4">
      <?= htmlspecialchars($_SESSION['success_message']);
      unset($_SESSION['success_message']); ?>
    </div>
  <?php endif; ?>

  <form method="POST" class="space-y-6">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

    <div class="space-y-2">
      <label class="block font-semibold">Integracja z Facebookiem</label>
      <input type="text" name="facebook_page_id" value="<?= htmlspecialchars($facebook_page_id) ?>" placeholder="Page ID" class="w-full border rounded px-3 py-2">
      <input type="text" name="facebook_token" value="<?= htmlspecialchars($facebook_token) ?>" placeholder="Token dostępu" class="w-full border rounded px-3 py-2">
      <a href="/api/facebook/start.php" class="inline-block bg-blue-600 text-white px-4 py-2 rounded">🔗 Połącz z Facebookiem</a>
      <p class="text-sm text-gray-600">Jeśli chcesz rozłączyć konto, kliknij <a href="/api/facebook/disconnect.php" class="text-blue-600 underline">tutaj</a>.</p>
    </div>

    <hr class="my-4">

    <div>
      <label class="block font-semibold">Furgonetka API Key</label>
      <input type="text" name="furgonetka_api_key" value="<?= htmlspecialchars($furgonetka_api_key) ?>" class="w-full border rounded px-3 py-2">
    </div>

    <div>
      <label class="block font-semibold">Nadawca – Imię i nazwisko</label>
      <input type="text" name="furgonetka_sender_name" value="<?= htmlspecialchars($furgonetka_sender_name) ?>" class="w-full border rounded px-3 py-2">
    </div>

    <div>
      <label class="block font-semibold">Nadawca – Telefon</label>
      <input type="text" name="furgonetka_sender_phone" value="<?= htmlspecialchars($furgonetka_sender_phone) ?>" class="w-full border rounded px-3 py-2">
    </div>

    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">💾 Zapisz ustawienia</button>
  </form>
</div>

<?php require_once __DIR__ . '/../../../layout/layout_footer.php'; ?>