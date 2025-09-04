<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../log.php'; // ← ładuje logg()
require_once __DIR__ . '/../../engine/centralMessaging/CwHelper.php';
require_once __DIR__ . '/../../engine/centralMessaging/Cw.php';

use Olaj\CW\CwHelper; // ✅ alias do klasy helperów CW
/**
 * Obsługa komendy "daj" – dodanie produktu do otwartej paczki klienta
 */
function handle_daj(int $owner_id, string $platform_id, string $text, string $platform = 'manual', bool $is_comment = false): array
{
    global $pdo;
    $logFile = __DIR__ . '/log_daj.txt';
    file_put_contents($logFile, date('Y-m-d H:i:s') . " | START | TEXT=\"{$text}\"\n", FILE_APPEND);
    logg('info', 'parser_daj', 'start', ['text' => $text, 'platform_id' => $platform_id]);

    try {
        // 1. Regex — wspiera: daj abc123, DAJ 123 + 2, daj a01 × 3
        if (!preg_match('/^daj\s*([a-zA-Z0-9]{3,})\s*(?:[+x×]?\s*(\d+))?$/iu', $text, $matches)) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " | ❌ NIEPASUJE | {$text}\n", FILE_APPEND);
            logg('debug', 'parser_daj', 'regex.no_match', ['text' => $text]);
            return ['error' => 'Niepoprawna komenda'];
        }

        $code_raw = trim($matches[1]);
        $qty      = isset($matches[2]) ? (int)$matches[2] : 1;

        // 2. Produkt
        logg('debug', 'parser_daj', 'product.lookup', ['code' => $code_raw, 'owner_id' => $owner_id]);
        $stmt = $pdo->prepare("SELECT * FROM products WHERE owner_id = :oid AND code = :code LIMIT 1");
        $stmt->execute(['oid' => $owner_id, 'code' => $code_raw]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " | ❌ BRAK PRODUKTU {$code_raw}\n", FILE_APPEND);
            logg('info', 'parser_daj', 'product.not_found', ['code' => $code_raw]);
            return ['error' => 'Nie znaleziono produktu'];
        }

        // 3. Klient
       $client = CwHelper::fetchOrCreateClient($pdo, $owner_id, $platform, $platform_id);

        $client_id    = (int)$client['id'];
        $client_token = (string)$client['token'];
        logg('debug', 'parser_daj', 'client.ok', ['client_id' => $client_id, 'token' => $client_token]);

        // 4. Aktywna paczka
        list($order_id, $order_group_id, $checkout_token) = getActiveTokenForClient($pdo, $owner_id, $client_id);
        logg('debug', 'parser_daj', 'checkout_token.ok', [
            'client_id' => $client_id,
            'order_id'  => $order_id,
            'group_id'  => $order_group_id,
            'token'     => $checkout_token,
        ]);

        // 5. Dodanie pozycji
        $source = mapPlatformToSource($platform, $is_comment);
        addItemByToken(
            $pdo,
            $checkout_token,
            (int)$product['id'],
            $qty,
            (float)$product['price'],
            $source,
            (string)($product['name'] ?? '')
        );
        logg('info', 'parser_daj', 'item.added', [
            'product_id' => $product['id'],
            'qty'        => $qty,
            'token'      => $checkout_token
        ]);

        // 6. Odpowiedź do klienta (checkout link)
       CwHelper::sendAutoReplyCheckoutWithToken(
    $pdo,
    $client_id,
    $checkout_token,
    $product['name'],
    $qty,
    $product['price'],
    $owner_id,
    $platform // 🆕 tu wchodzi platforma
);




        file_put_contents($logFile, date('Y-m-d H:i:s') . " | ✅ DODANO DO PACZKI: {$code_raw} x{$qty}\n", FILE_APPEND);

        return [
            'success'        => true,
            'order_id'       => $order_id,
            'order_group_id' => $order_group_id,
            'token'          => $checkout_token
        ];

    } catch (Throwable $e) {
        logg('error', 'parser_daj', 'exception', ['error' => $e->getMessage()]);
        file_put_contents($logFile, date('Y-m-d H:i:s') . " | ❌ WYJĄTEK: {$e->getMessage()}\n", FILE_APPEND);
        return ['error' => 'Błąd: ' . $e->getMessage()];
    }
}
