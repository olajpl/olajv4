<?php
// admin/settings/ogolne/index.php — V4: Ustawienia ogólne (CSRF, walidacje)
declare(strict_types=1);

session_start();
if (empty($_SESSION['user']) || !isset($_SESSION['user']['id'])) {
  $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/admin/settings/ogolne/');
  header("Location: /auth/login.php?redirect={$redirect}");
  exit;
}

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/settings.php';
require_once __DIR__ . '/../../../includes/log.php';      // wlog()
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

$ERRORS = [];

// GET – wczytanie aktualnych wartości
$company_name  = (string)get_setting($owner_id, 'company_name');
$contact_email = (string)get_setting($owner_id, 'contact_email');
$dark_mode     = (string)get_setting($owner_id, 'dark_mode'); // '1' | '0'

// POST – zapis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf'])) {
      throw new RuntimeException('Błędny token bezpieczeństwa (CSRF). Odśwież stronę i spróbuj ponownie.');
    }

    $company_name_new  = trim((string)($_POST['company_name']  ?? ''));
    $contact_email_new = trim((string)($_POST['contact_email'] ?? ''));
    $dark_mode_new     = isset($_POST['dark_mode']) ? '1' : '0';

    // Walidacje light
    if ($company_name_new !== '' && mb_strlen($company_name_new) > 191) {
      $ERRORS[] = 'Nazwa firmy jest zbyt długa (max 191 znaków).';
      $company_name_new = mb_substr($company_name_new, 0, 191);
    }
    if ($contact_email_new !== '' && !filter_var($contact_email_new, FILTER_VALIDATE_EMAIL)) {
      $ERRORS[] = 'Nieprawidłowy adres e-mail.';
    }

    if (!$ERRORS) {
      set_setting($owner_id, 'company_name',  $company_name_new);
      set_setting($owner_id, 'contact_email', $contact_email_new);
      set_setting($owner_id, 'dark_mode',     $dark_mode_new);

      if (function_exists('wlog')) {
        wlog('settings.general.update', [
          'owner_id' => $owner_id,
          'dark_mode' => $dark_mode_new,
          'email_set' => $contact_email_new !== '' ? 1 : 0,
        ]);
      }

      $_SESSION['success_message'] = 'Ustawienia zapisane pomyślnie!';
      header("Location: /admin/settings/ogolne/"); // PRG pattern
      exit;
    } else {
      // przepisz do zmiennych, by zostały w formularzu
      $company_name  = $company_name_new;
      $contact_email = $contact_email_new;
      $dark_mode     = $dark_mode_new;
    }
  } catch (Throwable $e) {
    $ERRORS[] = 'Błąd zapisu: ' . $e->getMessage();
  }
}
?>
<div class="p-4 md:p-6">
  <a href="/admin/settings/" class="text-sm text-gray-500 hover:text-black flex items-center gap-1 mb-4">
    ← Powrót do ustawień
  </a>

  <h1 class="text-gray-500 text-sm uppercase tracking-wide mb-4">Ustawienia ogólne</h1>

  <?php if ($ERRORS): ?>
    <div class="bg-red-50 text-red-800 text-sm px-4 py-2 rounded mb-4 border border-red-200">
      <ul class="list-disc ml-5">
        <?php foreach ($ERRORS as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="bg-green-100 text-green-800 text-sm px-4 py-2 rounded mb-4 border border-green-200">
      <?= htmlspecialchars($_SESSION['success_message']);
      unset($_SESSION['success_message']); ?>
    </div>
  <?php endif; ?>

  <form method="POST" class="space-y-4 max-w-xl" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

    <div>
      <label class="block text-sm text-gray-700 mb-1">Nazwa firmy</label>
      <input
        type="text"
        name="company_name"
        maxlength="191"
        value="<?= htmlspecialchars($company_name) ?>"
        class="w-full border rounded px-3 py-2"
        placeholder="Twoja sp. z o.o.">
    </div>

    <div>
      <label class="block text-sm text-gray-700 mb-1">E-mail kontaktowy</label>
      <input
        type="email"
        name="contact_email"
        value="<?= htmlspecialchars($contact_email) ?>"
        class="w-full border rounded px-3 py-2"
        placeholder="kontakt@firma.pl">
    </div>

    <div class="flex items-center gap-2">
      <input type="checkbox" id="dark_mode" name="dark_mode" value="1" <?= ($dark_mode === '1') ? 'checked' : '' ?> class="h-4 w-4">
      <label for="dark_mode" class="text-sm text-gray-700">Włącz tryb ciemny</label>
    </div>

    <button type="submit" class="bg-pink-500 hover:bg-pink-600 text-white px-4 py-2 rounded">
      Zapisz ustawienia
    </button>
  </form>
</div>

<?php require_once __DIR__ . '/../../../layout/layout_footer.php'; ?>