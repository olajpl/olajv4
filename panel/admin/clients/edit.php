<?php
// admin/clients/edit.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$pdo = $pdo ?? ($db ?? null);
if (!$pdo) {
  die('Brak połączenia z bazą.');
}

session_start();

$client_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$owner_id  = isset($_SESSION['user']['owner_id']) ? (int)$_SESSION['user']['owner_id'] : 0;

if ($client_id <= 0 || $owner_id <= 0) {
  header("Location: index.php");
  exit;
}

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// Pobierz dane klienta
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id AND owner_id = :oid");
$stmt->execute(['id' => $client_id, 'oid' => $owner_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) {
  $_SESSION['success_message'] = "Nie znaleziono klienta.";
  header("Location: index.php");
  exit;
}

// Zapis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(400);
    exit('Nieprawidłowy token bezpieczeństwa.');
  }

  $name  = trim((string)($_POST['name']  ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $phone = trim((string)($_POST['phone'] ?? ''));

  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['success_message'] = "Nieprawidłowy adres e-mail.";
    header("Location: edit.php?id=" . $client_id);
    exit;
  }

  $upd = $pdo->prepare("
    UPDATE clients
    SET name = :name, email = :email, phone = :phone
    WHERE id = :id AND owner_id = :oid
  ");
  $upd->execute([
    'name' => $name,
    'email' => ($email !== '' ? $email : null),
    'phone' => ($phone !== '' ? $phone : null),
    'id' => $client_id,
    'oid' => $owner_id
  ]);

  $_SESSION['success_message'] = "Zaktualizowano dane klienta.";
  header("Location: view.php?id=" . $client_id);
  exit;
}
?>
<!DOCTYPE html>
<html lang="pl">

<head>
  <meta charset="UTF-8">
  <title>Edycja klienta</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen py-8 px-4">
  <div class="max-w-xl mx-auto bg-white p-6 rounded-xl shadow">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">✏️ Edycja klienta</h1>
      <a href="view.php?id=<?= (int)$client['id'] ?>" class="text-gray-600 hover:underline">Anuluj</a>
    </div>

    <?php if (!empty($_SESSION['success_message'])): ?>
      <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
        <?= htmlspecialchars($_SESSION['success_message']) ?>
      </div>
      <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <form method="post" class="space-y-4" autocomplete="off" novalidate>
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

      <div>
        <label class="block text-sm font-medium">Imię i nazwisko</label>
        <input type="text" name="name" value="<?= htmlspecialchars($client['name'] ?? '') ?>" required class="w-full p-2 border rounded">
      </div>
      <div>
        <label class="block text-sm font-medium">Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($client['email'] ?? '') ?>" class="w-full p-2 border rounded" inputmode="email">
      </div>
      <div>
        <label class="block text-sm font-medium">Telefon</label>
        <input type="text" name="phone" value="<?= htmlspecialchars($client['phone'] ?? '') ?>" class="w-full p-2 border rounded" inputmode="tel">
      </div>

      <div class="flex justify-end pt-4">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Zapisz</button>
      </div>
    </form>
  </div>
</body>

</html>