<?php
// admin/clients/create.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

session_start();

// Ujednolicenie handlera DB
$pdo = $pdo ?? ($db ?? null);
if (!$pdo) {
  http_response_code(500);
  exit('Brak połączenia z bazą.');
}

// Autoryzacja
if (empty($_SESSION['user']['role']) || $_SESSION['user']['role'] !== 'superadmin') {
  http_response_code(403);
  exit('Brak dostępu');
}

// CSRF
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// Generator tokena
function generateToken(string $prefix = 'olaj'): string
{
  return $prefix . '_' . bin2hex(random_bytes(4)); // np. olaj_9af3b1c2
}

// Lista właścicieli
$ownersStmt = $pdo->query("SELECT id, name FROM owners ORDER BY name");
$owners = $ownersStmt->fetchAll(PDO::FETCH_ASSOC);

// Przycisk „Wygeneruj”
$generated_token = isset($_GET['generate_token']) ? generateToken() : '';

// Obsługa formularza
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(400);
    exit('Nieprawidłowy token bezpieczeństwa.');
  }

  $owner_id = (int)($_POST['owner_id'] ?? 0);
  $name     = trim((string)($_POST['name']  ?? ''));
  $email    = trim((string)($_POST['email'] ?? ''));
  $phone    = trim((string)($_POST['phone'] ?? ''));
  $token    = trim((string)($_POST['token'] ?? ''));

  if ($token === '')       $token = generateToken();
  if ($name === '') {
    $_SESSION['success_message'] = "Imię i nazwisko jest wymagane.";
    header("Location: create.php");
    exit;
  }
  if ($owner_id <= 0) {
    $_SESSION['success_message'] = "Wybierz firmę (owner).";
    header("Location: create.php");
    exit;
  }
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['success_message'] = "Nieprawidłowy e-mail.";
    header("Location: create.php");
    exit;
  }

  // Unikalność tokena
  $check = $pdo->prepare("SELECT id FROM clients WHERE token = :token LIMIT 1");
  $check->execute(['token' => $token]);
  if ($check->fetch()) {
    $_SESSION['success_message'] = "⚠️ Token '$token' już istnieje. Użyj innego lub kliknij „Wygeneruj”.";
    header("Location: create.php");
    exit;
  }

  try {
    $pdo->beginTransaction();

    $ins = $pdo->prepare("
      INSERT INTO clients (owner_id, name, email, phone, token)
      VALUES (:owner_id, :name, :email, :phone, :token)
    ");
    $ins->execute([
      'owner_id' => $owner_id,
      'name'     => $name,
      'email'    => ($email !== '' ? $email : null),
      'phone'    => ($phone !== '' ? $phone : null),
      'token'    => $token,
    ]);
    $client_id = (int)$pdo->lastInsertId();

    // Opcjonalny fake platform_id
    if (!empty($_POST['add_fake_platform'])) {
      $platformStmt = $pdo->prepare("
        INSERT INTO client_platform_ids (client_id, platform, platform_id)
        VALUES (:client_id, :platform, :platform_id)
      ");
      $platformStmt->execute([
        'client_id'   => $client_id,
        'platform'    => 'facebook',
        'platform_id' => 'FAKE_FB_' . $client_id,
      ]);
    }

    $pdo->commit();

    $_SESSION['success_message'] = "✅ Dodano nowego klienta #{$client_id}.";
    header("Location: view.php?id=" . $client_id);
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['success_message'] = "Błąd zapisu: " . htmlspecialchars($e->getMessage());
    header("Location: create.php");
    exit;
  }
}

include __DIR__ . '/../../layout/top_panel.php';
?>
<!DOCTYPE html>
<html lang="pl">

<head>
  <meta charset="UTF-8">
  <title>Dodaj klienta (superadmin)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen py-8 px-4">
  <div class="max-w-xl mx-auto bg-white p-6 rounded-xl shadow">
    <h1 class="text-2xl font-bold mb-4">➕ Dodaj klienta testowego (superadmin)</h1>

    <?php if (!empty($_SESSION['success_message'])): ?>
      <div class="bg-yellow-100 text-yellow-800 px-4 py-2 rounded mb-4">
        <?= htmlspecialchars($_SESSION['success_message']) ?>
      </div>
      <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <form method="post" class="space-y-4" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

      <div>
        <label class="block text-sm font-medium">Firma (owner_id)</label>
        <select name="owner_id" required class="w-full p-2 border rounded">
          <?php foreach ($owners as $o): ?>
            <option value="<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?> <?= htmlspecialchars($o['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium">Imię i nazwisko</label>
        <input type="text" name="name" required class="w-full p-2 border rounded">
      </div>

      <div>
        <label class="block text-sm font-medium">E-mail (opcjonalnie)</label>
        <input type="email" name="email" class="w-full p-2 border rounded" inputmode="email" placeholder="np. jan@olaj.pl">
      </div>

      <div>
        <label class="block text-sm font-medium">Telefon (opcjonalnie)</label>
        <input type="text" name="phone" class="w-full p-2 border rounded" inputmode="tel" placeholder="+48 600 000 000">
      </div>

      <div>
        <label class="block text-sm font-medium">Token klienta</label>
        <div class="flex gap-2">
          <input type="text" name="token" class="w-full p-2 border rounded" placeholder="np. olaj_ab12cd34" value="<?= htmlspecialchars($generated_token) ?>">
          <a href="?generate_token=1" class="bg-gray-300 px-3 py-2 rounded text-sm">🎲 Wygeneruj</a>
        </div>
        <p class="text-xs text-gray-500 mt-1">Token musi być unikalny w tabeli <code>clients</code>.</p>
      </div>

      <div>
        <label class="inline-flex items-center">
          <input type="checkbox" name="add_fake_platform" class="mr-2">
          Dodaj fałszywy platform_id (np. FAKE_FB_ID)
        </label>
      </div>

      <div class="text-right">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Zapisz klienta</button>
      </div>
    </form>
  </div>
</body>

</html>