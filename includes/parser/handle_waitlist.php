<?php
// includes/parser/handle_waitlist.php – obsługa przycisków z listy rezerwowej

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/handle_daj.php';

function handleWaitlistPostback(PDO $pdo, int $owner_id, int $client_id, string $payload): void {
    $logFile = __DIR__ . '/../../logs/webhook_log.txt';
    file_put_contents($logFile, "\n🔘 Obsługa postback: $payload\n", FILE_APPEND);

    if (preg_match('/^waitlist_(yes|no)_(\\d+)_(\\d+)$/', $payload, $m)) {
        $response = $m[1];
        $product_id = (int)$m[2];
        $reservation_id = (int)$m[3];

        // Sprawdź rezerwację
        $stmt = $pdo->prepare("SELECT * FROM product_reservations WHERE id = ? AND client_id = ? AND status = 'powiadomiony'");
        $stmt->execute([$reservation_id, $client_id]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$reservation) {
            file_put_contents($logFile, "❌ Brak rezerwacji lub już obsłużona\n", FILE_APPEND);
            return;
        }

        if ($response === 'yes') {
            // 🔍 Pobierz platform_id klienta
            $stmt = $pdo->prepare("SELECT platform_id FROM client_platform_ids WHERE client_id = ? AND platform = 'facebook' LIMIT 1");
            $stmt->execute([$client_id]);
            $platform_id = $stmt->fetchColumn();
            file_put_contents($logFile, "📡 platform_id = $platform_id\n", FILE_APPEND);

            // 🔍 Pobierz kod produktu
            $stmt = $pdo->prepare("SELECT code FROM products WHERE id = ? AND owner_id = ? LIMIT 1");
            $stmt->execute([$product_id, $owner_id]);
            $code = $stmt->fetchColumn();
            file_put_contents($logFile, "🏷️ product_code = $code\n", FILE_APPEND);

            if ($platform_id && $code) {
                global $pdo; // Dla handle_daj
                $text = "daj $code";
                file_put_contents($logFile, "📤 Wywołuję handle_daj('$text')\n", FILE_APPEND);
                $result = handle_daj($owner_id, $platform_id, $text);
                file_put_contents($logFile, "📥 Wynik handle_daj: " . json_encode($result) . "\n", FILE_APPEND);
            } else {
                file_put_contents($logFile, "⚠️ Brak kodu produktu lub platform_id – nie wywołano handle_daj\n", FILE_APPEND);
            }

            sendMessengerMessageToClient($pdo, $owner_id, $client_id, "✅ Produkt został dodany do Twojego koszyka!");
        } else {
            sendMessengerMessageToClient($pdo, $owner_id, $client_id, "❌ OK, nie dodaję produktu.");
        }

        // 📝 Oznacz rezerwację jako zakończoną
        $stmt = $pdo->prepare("UPDATE product_reservations SET status = 'anulowany' WHERE id = ?");
        $stmt->execute([$reservation_id]);

        file_put_contents($logFile, "✔️ Zaktualizowano rezerwację ID $reservation_id\n", FILE_APPEND);
    }
}
