<?php
// admin/suppliers/create.php — Dodaj dostawcę (Olaj.pl V4)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$owner_id   = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($owner_id <= 0) {
  http_response_code(403);
  exit('❌ Brak dostępu.');
}

$page_title = "Dodaj dostawcę";
require_once __DIR__ . '/../../layout/layout_header.php';
?>
<div class="p-6">
  <h1 class="text-2xl font-bold mb-6">➕ Dodaj nowego dostawcę</h1>

  <div class="bg-white rounded shadow p-6 max-w-2xl">
    <form method="post" action="store.php" class="space-y-4" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <div>
        <label class="block font-medium mb-1">Nazwa <span class="text-red-600">*</span></label>
        <input type="text" name="name" required class="w-full border p-2 rounded" maxlength="255" autofocus>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block font-medium mb-1">Email</label>
          <input type="email" name="email" class="w-full border p-2 rounded" maxlength="255" placeholder="np. faktury@firma.pl">
        </div>
        <div>
          <label class="block font-medium mb-1">Telefon</label>
          <input type="text" name="phone" class="w-full border p-2 rounded" maxlength="64" placeholder="+48 600 000 000">
        </div>
      </div>
      <div>
        <label class="block font-medium mb-1">Adres</label>
        <input type="text" name="address" class="w-full border p-2 rounded" maxlength="255">
      </div>
      <div>
        <label class="block font-medium mb-1">Notatka (wewnętrzna)</label>
        <textarea name="note" rows="3" class="w-full border p-2 rounded" placeholder="np. kontakt do opiekuna, godziny dostaw…"></textarea>
      </div>
      <div>
        <label class="block font-medium mb-1">Box (trafi do metadata)</label>
        <input type="text" name="box" class="w-full border p-2 rounded" maxlength="255" placeholder="np. ID kontenera, kod kontraktu">
      </div>
      <div class="text-right pt-4">
        <a href="index.php" class="px-4 py-2 border rounded mr-2">Anuluj</a>
        <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded hover:bg-green-700">Zapisz</button>
      </div>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>