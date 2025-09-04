<?php
// import_clients.php – Import klientów z pełnymi danymi (imię, email, telefon) + token
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

$owner_id = 1; // <- podmień na właściwy ID właściciela
$prefix = 'olaj-';

// Sprawdzenie istnienia pliku JSON
$json_path = __DIR__ . '/clients_full.json';
if (!file_exists($json_path)) {
    die("❌ Plik clients_full.json nie istnieje w: $json_path\n");
}

// Załaduj klientów
$external_clients = json_decode(file_get_contents($json_path), true);
if ($external_clients === null) {
    die("❌ Błąd podczas dekodowania JSON: " . json_last_error_msg() . "\n");
}

echo "📥 Znaleziono " . count($external_clients) . " rekordów do przetworzenia.\n";

$added = 0;
$skipped = 0;
foreach ($external_clients as $entry) {
    $name = trim($entry['name'] ?? '');
    $email = trim($entry['email'] ?? '');
    $phone = trim($entry['phone'] ?? '');

    echo "🔍 Sprawdzam: $name – $email – $phone\n";

    if (!$name || !$email || !$phone) {
        echo "⚠️  Pominięto – brak danych\n";
        continue;
    }

    // Sprawdź, czy klient już istnieje
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE (email = ? OR phone = ?) AND owner_id = ? LIMIT 1");
    $stmt->execute([$email, $phone, $owner_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        echo "↩️  Pominięto – istnieje\n";
        $skipped++;
        continue;
    }

    // Wygeneruj unikalny token w formacie olaj-XXXX
    do {
        $token = $prefix . rand(1000, 9999);
        $check = $pdo->prepare("SELECT id FROM clients WHERE token = ?");
        $check->execute([$token]);
    } while ($check->fetch());

    $stmt = $pdo->prepare("INSERT INTO clients (owner_id, name, email, phone, token) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$owner_id, $name, $email, $phone, $token]);
    echo "✅ Dodano: $name [$token]\n";
    $added++;
}

echo "\n📊 Podsumowanie: ✅ Dodano: $added | ❌ Duplikaty: $skipped\n";
