<?php
// includes/parser/handle_zamknij.php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

function handle_zamknij(int $owner_id, string $platform_id, string $platform = 'facebook'): array
{
    global $pdo;
    $logFile = __DIR__ . '/log_zamknij.txt';

    // Stałe statusów (pod V2)
    $COL_STATUS = 'order_status';
    $ST_NOWE    = 'nowe';
    $ST_OPEN    = 'otwarta_paczka';
    $ST_SEND    = 'do_wyslania';

    file_put_contents($logFile, date('Y-m-d H:i:s') . " | 🧩 Wejście | owner_id=$owner_id | platform=$platform | platform_id=$platform_id\n", FILE_APPEND);

    // 🔍 Klient po platformie
    $stmt = $pdo->prepare("
        SELECT c.id, c.token
        FROM clients c
        JOIN client_platform_ids cp ON cp.client_id = c.id
        WHERE cp.platform = :platform AND cp.platform_id = :platform_id AND c.owner_id = :owner_id
        LIMIT 1
    ");
    $stmt->execute([
        ':platform'    => $platform,
        ':platform_id' => $platform_id,
        ':owner_id'    => $owner_id
    ]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        file_put_contents($logFile, "❌ Nie znaleziono klienta\n", FILE_APPEND);
        return ['error' => 'Nie znaleziono klienta'];
    }

    $client_id = (int)$client['id'];
    $client_token = $client['token'];

    // 🔍 Ostatnie aktywne zamówienie (nowe/otwarta_paczka)
    $stmt = $pdo->prepare("
        SELECT id, {$COL_STATUS} AS s
        FROM orders
        WHERE owner_id = :owner_id AND client_id = :client_id
          AND {$COL_STATUS} IN (:st1, :st2)
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':owner_id'  => $owner_id,
        ':client_id' => $client_id,
        ':st1'       => $ST_NOWE,
        ':st2'       => $ST_OPEN,
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        file_put_contents($logFile, "❌ Brak zamówienia do zamknięcia\n", FILE_APPEND);
        return ['error' => 'Nie znaleziono aktywnego zamówienia'];
    }

    $order_id = (int)$order['id'];
    $order_status = $order['s'];

    // 🔗 Spróbuj znaleźć ostatni checkout_token (żeby dać precyzyjny link)
    $stmt = $pdo->prepare("SELECT checkout_token FROM order_groups WHERE order_id = :oid ORDER BY id DESC LIMIT 1");
    $stmt->execute([':oid' => $order_id]);
    $checkout_token = $stmt->fetchColumn();

    // 📦 Jeśli status = NOWE → przypomnienie o potwierdzeniu (bez zmiany statusu)
    if ($order_status === $ST_NOWE) {
        file_put_contents($logFile, "ℹ️ Paczka NOWE – wysyłam przypomnienie\n", FILE_APPEND);

        $link = $checkout_token
            ? "https://olaj.pl/checkout/summary.php?token=" . urlencode($checkout_token)
            : "https://olaj.pl/moje.php?token=" . urlencode($client_token);

        $text = "📦 Twoje zamówienie nie jest jeszcze potwierdzone. Kliknij poniżej, aby dokończyć.";
        sendStructuredMessage($pdo, $owner_id, $client_id, $text, [
            ['type' => 'web_url', 'url' => $link, 'title' => '📝 Dokończ zamówienie']
        ]);

        return ['success' => true, 'message' => 'Wysłano przypomnienie o potwierdzeniu zamówienia.'];
    }

    // ✅ Inaczej: zamknij (do_wyslania)
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE orders SET {$COL_STATUS} = :st WHERE id = :id");
        $stmt->execute([':st' => $ST_SEND, ':id' => $order_id]);

        $pdo->commit();
        file_put_contents($logFile, "✅ Zamknięto zamówienie ID={$order_id} → {$ST_SEND}\n", FILE_APPEND);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        file_put_contents($logFile, "❌ Błąd zamykania: {$e->getMessage()}\n", FILE_APPEND);
        return ['error' => 'Błąd podczas zamykania zamówienia'];
    }

    // 📩 Potwierdzenie zamknięcia (Twoja automatyzacja)
    $context = ['client_name' => ''];
    sendAutoReply($pdo, $owner_id, $client_id, 'zamkniecie_paczki', $context, $platform);

    return ['success' => true, 'message' => 'Zamówienie zamknięte i wysłano potwierdzenie.'];
}
