<?php
// fb_test_comment.php – lokalny symulator komentarza Facebook
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/parser.php';

$owner_id = 1; // 👈 ustaw ID właściciela
$platform_id = '123456789'; // 👈 symulowany senderId (ID komentującego)
$messageText = 'daj 1001+2'; // 👈 treść komentarza (np. "daj 1120+2")



// Symuluj klienta, jeśli nie istnieje
$client = fetchOrCreateClient($pdo, $owner_id, 'facebook', $platform_id);
$client_id = $client['id'] ?? null;



// Symulowane wywołanie handlera z komentarzem
$result = handle_daj(
    $owner_id,
    $platform_id,
    $messageText,
    'facebook', // platforma
    true         // is_comment = TRUE => komentarz FB
);

file_put_contents(
    __DIR__ . '/../logs/fb_test_comment.log',
    "📤 Wynik: " . json_encode($result) . "\n",
    FILE_APPEND
);

if (!empty($result['success'])) {
    echo "✅ Komentarz przetworzony: dodano do zamówienia.";
} else {
    echo "❌ Błąd: " . ($result['error'] ?? 'Nieznany');
}
