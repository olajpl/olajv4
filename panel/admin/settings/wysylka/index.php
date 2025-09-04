<?php
// admin/settings/wysylka/index.php — V4: Ustawienia wysyłki (CSRF, walidacje, PRG)
declare(strict_types=1);

session_start();
if (empty($_SESSION['user']) || !isset($_SESSION['user']['id'])) {
  $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/admin/settings/wysylka/');
  header("Location: /auth/login.php?redirect={$redirect}");
  exit;
}

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/settings.php';
require_once __DIR__ . '/../../../includes/log.php'; // wlog()
require_once __DIR__ . '/../../../layout/layout_header.php';
require_once __DIR__ . '/../../../layout/top_panel.php';

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

// Helpery
function err(string $m): string
{
  return '<li>' . htmlspecialchars($m) . '</li>';
}
function asFloat($v): float
{
  return (float)str_replace(',', '.', (string)$v);
}

$ERRORS = [];

// --- Sprawdź połączenie z Furgonetką (kolumna broker) ---
$is_connected = false;
try {
  $stmt = $pdo->prepare("SELECT access_token FROM shipping_integrations WHERE owner_id = ? AND broker = 'furgonetka' LIMIT 1");
  $stmt->execute([$owner_id]);
  $furgonetka_integration = $stmt->fetch(PDO::FETCH_ASSOC);
  $is_connected = $furgonetka_integration && !empty($furgonetka_integration['access_token']);
} catch (Throwable $e) {
  // jak padnie, to UI pokaże brak połączenia – nie wysypujemy strony
}

// --- POST: zapisy/akcje ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf'])) {
      throw new RuntimeException('Błędny token CSRF. Odśwież stronę i spróbuj ponownie.');
    }

    // Walidacje globalne
    $shipping_default_method  = trim((string)($_POST['shipping_default_method'] ?? ''));
    $shipping_max_package_w   = trim((string)($_POST['shipping_max_package_weight'] ?? ''));
    $shipping_auto_add_cost   = isset($_POST['shipping_auto_add_cost']) ? '1' : '0';

    $maxW = $shipping_max_package_w === '' ? null : asFloat($shipping_max_package_w);
    if ($maxW !== null && $maxW < 0) {
      $ERRORS[] = 'Maksymalna waga paczki nie może być ujemna.';
    }

    // Walidacje/akcje metod
    $actionNew   = (isset($_POST['new_method_name']) || isset($_POST['new_method_price']));
    $actionEdit  = (isset($_POST['edit_method_id']) && isset($_POST['edit_method_price']));
    $actionDel   = isset($_POST['delete_method_id']);

    if ($actionNew) {
      $newName  = trim((string)($_POST['new_method_name'] ?? ''));
      $newPrice = ($_POST['new_method_price'] ?? '') === '' ? null : asFloat($_POST['new_method_price']);
      if ($newName === '') $ERRORS[] = 'Nazwa nowej metody nie może być pusta.';
      if ($newPrice !== null && $newPrice < 0) $ERRORS[] = 'Cena nowej metody nie może być ujemna.';
    }

    if ($actionEdit) {
      $editId    = (int)$_POST['edit_method_id'];
      $editPrice = asFloat($_POST['edit_method_price']);
      if ($editId <= 0) $ERRORS[] = 'Niepoprawne ID metody do edycji.';
      if ($editPrice < 0) $ERRORS[] = 'Cena metody nie może być ujemna.';
    }

    if ($actionDel) {
      $delId = (int)$_POST['delete_method_id'];
      if ($delId <= 0) $ERRORS[] = 'Niepoprawne ID metody do usunięcia.';
    }

    if ($ERRORS) {
      throw new RuntimeException('Walidacja nie powiodła się.');
    }

    // Transakcja na operacje
    $pdo->beginTransaction();

    // Zapis ustawień globalnych
    set_setting($owner_id, 'shipping_default_method', $shipping_default_method);
    set_setting($owner_id, 'shipping_max_package_weight', $maxW === null ? '' : (string)$maxW);
    set_setting($owner_id, 'shipping_auto_add_cost', $shipping_auto_add_cost);

    // Dodanie nowej metody
    if ($actionNew && $newName !== '') {
      $stmt = $pdo->prepare("INSERT INTO shipping_methods (owner_id, name, default_price) VALUES (?, ?, ?)");
      $stmt->execute([$owner_id, $newName, $newPrice ?? 0.0]);
    }

    // Edycja istniejącej metody
    if ($actionEdit) {
      $stmt = $pdo->prepare("UPDATE shipping_methods SET default_price = ? WHERE id = ? AND owner_id = ?");
      $stmt->execute([$editPrice, $editId, $owner_id]);
    }

    // Usuwanie metody
    if ($actionDel) {
      $stmt = $pdo->prepare("DELETE FROM shipping_methods WHERE id = ? AND owner_id = ?");
      $stmt->execute([$delId, $owner_id]);
    }

    $pdo->commit();

    if (function_exists('wlog')) {
      wlog('settings.shipping.update', [
        'owner_id' => $owner_id,
        'default'  => $shipping_default_method,
        'auto_add' => (int)($shipping_auto_add_cost === '1'),
        'actions'  => [
          'new'  => $actionNew ? 1 : 0,
          'edit' => $actionEdit ? 1 : 0,
          'del'  => $actionDel ? 1 : 0,
        ],
      ]);
    }

    $_SESSION['success_message'] = "Zapisano ustawienia wysyłki.";
    header('Location: index.php');
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $ERRORS[] = 'Błąd zapisu: ' . $e->getMessage();
  }
}

// GET – wczytanie aktualnych ustawień
$default_method     = (string)get_setting($owner_id, 'shipping_default_method');
$max_package_weight = (string)get_setting($owner_id, 'shipping_max_package_weight');
$auto_add_cost      = (string)get_setting($owner_id, 'shipping_auto_add_cost');

// Lista metod wysyłki
$shipping_methods = [];
try {
  $stmt = $pdo->prepare("SELECT id, name, default_price FROM shipping_methods WHERE owner_id = ? ORDER BY id ASC");
  $stmt->execute([$owner_id]);
  $shipping_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // zostaw pusto
}
?>
<div class="container mx-auto p-4">
  <div class="flex items-center justify-between mb-4">
    <a href="../" class="text-sm text-blue-600 hover:underline flex items-center gap-1">
      <span class="text-lg">←</span> Wróć do ustawień
    </a>
    <h1 class="text-2xl font-bold">Ustawienia wysyłki</h1>
  </div>

  <?php if ($ERRORS): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-2 rounded mb-4">
      <ul class="list-disc ml-5"><?= implode('', $ERRORS) ?></ul>
    </div>
  <?php endif; ?>

  <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="bg-green-100 text-green-800 p-3 rounded mb-4">
      <?= htmlspecialchars($_SESSION['success_message']);
      unset($_SESSION['success_message']); ?>
    </div>
  <?php endif; ?>

  <form method="POST" class="space-y-4 mb-6">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

    <div>
      <label class="block font-semibold">Domyślna metoda wysyłki</label>
      <input type="text" name="shipping_default_method" value="<?= htmlspecialchars($default_method) ?>" class="w-full border rounded px-3 py-2" placeholder="np. InPost, DPD, Odbiór osobisty">
    </div>

    <div>
      <label class="block font-semibold">Maksymalna waga paczki (kg)</label>
      <input type="number" step="0.1" min="0" name="shipping_max_package_weight" value="<?= htmlspecialchars($max_package_weight) ?>" class="w-full border rounded px-3 py-2">
    </div>

    <div class="flex items-center">
      <input type="checkbox" name="shipping_auto_add_cost" id="shipping_auto_add_cost" value="1" <?= ($auto_add_cost === '1') ? 'checked' : '' ?> class="mr-2">
      <label for="shipping_auto_add_cost">Automatycznie dodawaj koszt wysyłki do pierwszego zamówienia</label>
    </div>

    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">💾 Zapisz ustawienia</button>
  </form>

  <div class="border-t pt-6 mb-6">
    <h2 class="text-xl font-bold mb-3">Dostępne metody wysyłki</h2>
    <ul class="mb-4 space-y-2">
      <?php foreach ($shipping_methods as $method): ?>
        <li class="border rounded p-3 flex flex-col md:flex-row md:items-center md:justify-between gap-2">
          <div>
            📦 <strong><?= htmlspecialchars((string)$method['name']) ?></strong> —
            <?= isset($method['default_price']) ? number_format((float)$method['default_price'], 2, ',', ' ') : '—' ?> zł
          </div>
          <div class="flex items-center gap-2">
            <form method="POST" class="flex items-center gap-2">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="edit_method_id" value="<?= (int)$method['id'] ?>">
              <input type="number" step="0.01" min="0" name="edit_method_price" value="<?= htmlspecialchars((string)($method['default_price'] ?? '')) ?>" class="border rounded px-2 py-1 w-28">
              <button type="submit" class="bg-yellow-500 text-white px-3 py-1 rounded">💾 Zapisz</button>
            </form>
            <form method="POST" onsubmit="return confirm('Na pewno usunąć tę metodę?')">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="delete_method_id" value="<?= (int)$method['id'] ?>">
              <button type="submit" class="bg-red-600 text-white px-3 py-1 rounded">🗑</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>

    <form method="POST" class="flex flex-col md:flex-row items-start md:items-end gap-2">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="text" name="new_method_name" placeholder="Nazwa metody" class="border rounded px-3 py-2 w-full md:w-1/2">
      <input type="number" step="0.01" min="0" name="new_method_price" placeholder="Cena" class="border rounded px-3 py-2 w-full md:w-1/4">
      <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">➕ Dodaj metodę</button>
    </form>
  </div>

  <div class="border-t pt-6">
    <h2 class="text-xl font-bold mb-3">Integracja z Furgonetką</h2>
    <?php if ($is_connected): ?>
      <p class="text-green-700 mb-3">Połączono z Furgonetką ✅</p>
    <?php else: ?>
      <a href="/api/furgonetka/connect.php" class="inline-block bg-purple-600 text-white px-4 py-2 rounded">
        🔗 Połącz z Furgonetką
      </a>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../../layout/layout_footer.php'; ?>