<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

// Jeśli nie masz tej funkcji:
if (!function_exists('getPDO')) {
    function getPDO() {
        global $pdo;
        return $pdo;
    }
}

$pageId = '703311839531468'; // <- Twój page_id z bazy
$recipientId = '23934739002878727'; // <- ID odbiorcy z webhooka
$message = "🔧 Testowa wiadomość z test_send.php!";

// START
file_put_contents(__DIR__ . '/../logs/fb_log.txt', "\n🚀 TEST sendMessengerMessage() START\n", FILE_APPEND);
file_put_contents(__DIR__ . '/../logs/fb_log.txt', "📦 Parametry wejściowe: pageId=$pageId | recipientId=$recipientId | message=$message\n", FILE_APPEND);

// Sprawdź połączenie z bazą
$pdo = getPDO();
if (!$pdo) {
    file_put_contents(__DIR__ . '/../logs/fb_log.txt', "❌ Błąd: Brak PDO\n", FILE_APPEND);
    exit("Brak PDO");
}

// Pobierz page_token
$stmt = $pdo->prepare("SELECT page_token FROM facebook_tokens WHERE page_id = ? LIMIT 1");
$stmt->execute([$pageId]);
$pageToken = $stmt->fetchColumn();

if (!$pageToken) {
    file_put_contents(__DIR__ . '/../logs/fb_log.txt', "❌ Brak page_token dla page_id=$pageId\n", FILE_APPEND);
    exit("Brak page_token");
}

// Przygotuj dane
$url = "https://graph.facebook.com/v18.0/me/messages?access_token=$pageToken";
$payload = [
    'recipient' => ['id' => $recipientId],
    'message' => ['text' => $message],
    'messaging_type' => 'RESPONSE'
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload)
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Loguj wynik
file_put_contents(__DIR__ . '/../logs/fb_log.txt', "➡️ Wysyłka testowa do odbiorcy: $recipientId\n", FILE_APPEND);
file_put_contents(__DIR__ . '/../logs/fb_log.txt', "📤 Wiadomość: $message\n", FILE_APPEND);
file_put_contents(__DIR__ . '/../logs/fb_log.txt', "🌐 HTTP CODE: $httpCode\n", FILE_APPEND);
file_put_contents(__DIR__ . '/../logs/fb_log.txt', "📬 Odpowiedź Facebooka:\n$response\n", FILE_APPEND);
file_put_contents(__DIR__ . '/../logs/fb_log.txt', "🐞 Błąd CURL (jeśli wystąpił): $error\n", FILE_APPEND);

echo "✅ Test zakończony. Sprawdź logs/fb_log.txt\n";
