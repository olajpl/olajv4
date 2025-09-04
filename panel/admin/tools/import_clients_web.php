<?php
// admin/tools/import_clients_web.php – Webowy formularz importu klientów z JSON
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/layout_header.php';

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
$prefix = 'olaj-';
$added = 0;
$skipped = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['json_file'])) {
    $file = $_FILES['json_file']['tmp_name'];
    $data = json_decode(file_get_contents($file), true);

    if (!is_array($data)) {
        echo "<p class='text-red-600'>❌ Błąd podczas wczytywania JSON.</p>";
    } else {
        foreach ($data as $entry) {
            $name = trim($entry['name'] ?? '');
            $email = trim($entry['email'] ?? '');
            $phone = trim($entry['phone'] ?? '');
            if (!$name || !$email || !$phone) continue;

            $stmt = $pdo->prepare("SELECT id FROM clients WHERE (email = ? OR phone = ?) AND owner_id = ? LIMIT 1");
            $stmt->execute([$email, $phone, $owner_id]);
            if ($stmt->fetch()) {
                $skipped++;
                continue;
            }

            do {
                $token = $prefix . rand(1000, 9999);
                $check = $pdo->prepare("SELECT id FROM clients WHERE token = ?");
                $check->execute([$token]);
            } while ($check->fetch());

            $stmt = $pdo->prepare("INSERT INTO clients (owner_id, name, email, phone, token) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$owner_id, $name, $email, $phone, $token]);
            $added++;
        }
        echo "<p class='text-green-600'>✅ Dodano: $added | ❌ Duplikaty: $skipped</p>";
    }
}
?>

<div class="max-w-xl mx-auto py-10">
  <h1 class="text-2xl font-bold mb-4">📤 Import klientów z JSON</h1>
  <form method="post" enctype="multipart/form-data" class="space-y-4">
    <input type="file" name="json_file" accept="application/json" required class="block">
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Importuj</button>
  </form>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
