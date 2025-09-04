<?php
/**
 * helpers.php — wspólne helpery
 */

function getPDO()
{
    global $pdo;
    return $pdo;
}

function fetchOrCreateClient(PDO $pdo, int $owner_id, string $platform, string $platform_id): array
{
    $stmt = $pdo->prepare("
        SELECT c.id, c.token
        FROM clients c
        JOIN client_platform_ids p ON p.client_id = c.id
        WHERE c.owner_id = :owner_id AND p.platform = :platform AND p.platform_id = :platform_id
        LIMIT 1
    ");
    $stmt->execute([
        ':owner_id'    => $owner_id,
        ':platform'    => $platform,
        ':platform_id' => $platform_id
    ]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($client) {
        return ['id' => (int)$client['id'], 'token' => (string)$client['token']];
    }

    $token = generate_client_token($owner_id);

    $pdo->prepare("INSERT INTO clients (owner_id, token, name) VALUES (:owner_id, :token, :name)")
        ->execute([
            ':owner_id' => $owner_id,
            ':token'    => $token,
            ':name'     => "Klient $token"
        ]);
    $client_id = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO client_platform_ids (client_id, platform, platform_id)
                   VALUES (:client_id, :platform, :platform_id)")
        ->execute([
            ':client_id'   => $client_id,
            ':platform'    => $platform,
            ':platform_id' => $platform_id
        ]);

    return ['id' => $client_id, 'token' => $token];
}

function generate_client_token($owner_id)
{
    global $pdo;

    if (!isset($pdo) || !$pdo instanceof PDO) {
        @file_put_contents(__DIR__ . '/../logs/webhook_log.txt', "❌ generate_client_token: brak PDO\n", FILE_APPEND);
        return 'olaj-fallback-' . random_int(1000, 9999);
    }

    do {
        $token = 'olaj' . random_int(1000, 9999);
        $stmt  = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE token = :token");
        $stmt->execute([':token' => $token]);
        $exists = (int)$stmt->fetchColumn() > 0;
    } while ($exists);

    return $token;
}

function getTemplateContent($owner_id, $type, $platform = 'facebook')
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT content FROM message_templates WHERE owner_id = ? AND type = ? AND platform = ? AND active = 1");
    $stmt->execute([$owner_id, $type, $platform]);
    return $stmt->fetchColumn() ?: null;
}

function get_message_templates(int $owner_id, string $platform = 'facebook'): array
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT type, content FROM message_templates WHERE owner_id = :owner_id AND platform = :platform");
    $stmt->execute(['owner_id' => $owner_id, 'platform' => $platform]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $templates = [];
    foreach ($results as $row) {
        $templates[$row['type']][] = $row['content'];
    }
    return $templates;
}

/* ==============================
   Facebook — proste wysyłki
   ============================== */

function sendMessengerMessage(string $pageId, string $recipientId, string $messageText): void
{
    @file_put_contents(__DIR__ . '/../logs/fb_log.txt', "🚀 START sendMessengerMessage\n", FILE_APPEND);
    @file_put_contents(__DIR__ . '/../logs/fb_log.txt', "📦 pageId=$pageId | recipientId=$recipientId | message=$messageText\n", FILE_APPEND);

    $pdo = getPDO();

    $stmt = $pdo->prepare("SELECT page_token FROM facebook_tokens WHERE page_id = ? LIMIT 1");
    $stmt->execute([$pageId]);
    $pageToken = $stmt->fetchColumn();

    if (!$pageToken) {
        @file_put_contents(__DIR__ . '/../logs/fb_log.txt', "❌ Brak page_token dla page_id=$pageId\n", FILE_APPEND);
        return;
    }

    $url = "https://graph.facebook.com/v18.0/me/messages?access_token=$pageToken";
    $payload = [
        'recipient'      => ['id' => $recipientId],
        'message'        => ['text' => $messageText],
        'messaging_type' => 'RESPONSE'
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);

    @file_put_contents(__DIR__ . '/../logs/fb_log.txt', "➡️ Wysyłka do: $recipientId\n📤 $messageText\n", FILE_APPEND);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);

    @file_put_contents(
        __DIR__ . '/../logs/fb_log.txt',
        "✅ HTTP: $httpCode\nRESPONSE: $response\nERROR: $err\n\n",
        FILE_APPEND
    );
}

function sendAutoReply(PDO $pdo, int $owner_id, int $client_id, string $type, array $context = [], string $platform = 'facebook')
{
    try {
        @file_put_contents(__DIR__ . '/../logs/webhook_log.txt', "📡 START sendAutoReply | type=$type | platform=$platform\n", FILE_APPEND);

        $templates = get_message_templates($owner_id, $platform);
        $variants  = $templates[$type] ?? [];

        if (empty($variants)) {
            @file_put_contents(__DIR__ . '/../logs/webhook_log.txt', "❌ Brak szablonów typu $type dla owner_id=$owner_id\n", FILE_APPEND);
            return false;
        }

        $template = $variants[array_rand($variants)];

        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{{' . $key . '}}'] = $value;
        }

        $message = strtr($template, $replacements);

        $stmt = $pdo->prepare("SELECT platform_id FROM client_platform_ids WHERE client_id = ? AND platform = ?");
        $stmt->execute([$client_id, $platform]);
        $platform_id = $stmt->fetchColumn();

        if (!$platform_id) {
            @file_put_contents(__DIR__ . '/../logs/webhook_log.txt', "❌ Brak platform_id dla klienta $client_id\n", FILE_APPEND);
            return false;
        }

        $stmt = $pdo->prepare("INSERT INTO messages (owner_id, client_id, platform, platform_id, message, parsed)
                               VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([$owner_id, $client_id, $platform, $platform_id, $message]);

        @file_put_contents(__DIR__ . '/../logs/webhook_log.txt', "🧪 AutoReply do $platform_id: $message\n", FILE_APPEND);

        $stmt = $pdo->prepare("SELECT page_id FROM facebook_tokens WHERE owner_id = ? LIMIT 1");
        $stmt->execute([$owner_id]);
        $page_id = $stmt->fetchColumn();

        if ($page_id) {
            if (function_exists('sendMessengerMessage')) {
                sendMessengerMessage($page_id, $platform_id, $message);
            } else {
                @file_put_contents(__DIR__ . '/../logs/webhook_log.txt', "❌ Brak funkcji sendMessengerMessage\n", FILE_APPEND);
            }
        } else {
            @file_put_contents(__DIR__ . '/../logs/webhook_log.txt', "❌ Brak page_id dla owner_id=$owner_id\n", FILE_APPEND);
        }

        return $message;
    } catch (Throwable $e) {
        @file_put_contents(__DIR__ . '/../logs/webhook_log.txt', "❌ WYJĄTEK sendAutoReply: " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}

// Prosty sender (nieużywany bezpośrednio powyżej, zostawiam zgodnie z Twoim plikiem)
function fb_send_message(string $recipient_id, string $message, string $page_token): string
{
    $url = "https://graph.facebook.com/v18.0/me/messages?access_token=" . urlencode($page_token);

    $payload = [
        'recipient' => ['id' => $recipient_id],
        'message'   => ['text' => $message]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        @file_put_contents(__DIR__ . '/../logs/webhook_log.txt', "❌ CURL ERROR: $error\n", FILE_APPEND);
    } else {
        @file_put_contents(__DIR__ . '/../logs/webhook_log.txt', "📬 fb_send_message response: $response\n", FILE_APPEND);
    }

    curl_close($ch);

    return $response ?: '';
}

function getPlatformIconComponent(string $platform): string
{
    return match ($platform) {
        'facebook' => '<x-facebook class="w-4 h-4 text-[#1877F2]" />',
        'chat'     => '<x-message-circle class="w-4 h-4 text-gray-500" />',
        'mobile'   => '<x-smartphone class="w-4 h-4 text-green-500" />',
        default    => '<x-help-circle class="w-4 h-4 text-gray-400" />',
    };
}

function sendStructuredMessage(PDO $pdo, int $owner_id, int $client_id, string $text, array $buttons): void
{
    // Odbiorca
    $stmt = $pdo->prepare("
        SELECT platform_id
        FROM client_platform_ids
        WHERE client_id = ? AND platform = 'facebook'
        ORDER BY message_sent_at DESC
        LIMIT 1
    ");
    $stmt->execute([$client_id]);
    $recipientId = $stmt->fetchColumn();
    if (!$recipientId) return;

    // Token strony
    $stmt = $pdo->prepare("SELECT page_token FROM facebook_tokens WHERE owner_id = ? LIMIT 1");
    $stmt->execute([$owner_id]);
    $pageToken = $stmt->fetchColumn();
    if (!$pageToken) return;

    // Wiadomość z przyciskami
    $payload = [
        'recipient' => ['id' => $recipientId],
        'message'   => [
            'attachment' => [
                'type'    => 'template',
                'payload' => [
                    'template_type' => 'button',
                    'text'          => $text,
                    'buttons'       => array_map(function ($b) {
                        if (($b['type'] ?? '') === 'web_url') {
                            return [
                                'type'  => 'web_url',
                                'title' => $b['title'],
                                'url'   => $b['url']
                            ];
                        }
                        return [
                            'type'    => 'postback',
                            'title'   => $b['title'],
                            'payload' => $b['payload']
                        ];
                    }, $buttons),
                ]
            ]
        ]
    ];

    $url = "https://graph.facebook.com/v17.0/me/messages?access_token=" . urlencode($pageToken);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/* ==============================
   Tokeny checkout / Payments
   ============================== */

function generateCheckoutToken()
{
    $raw = bin2hex(random_bytes(16));
    return vsprintf('%s-%s-%s-%s-%s-%s-%s-%s', str_split($raw, 4));
}

/**
 * Oblicza status płatności na podstawie payment_transactions
 * @return array{status:string,amount_paid:float,diff:float,last_payment_date:?string}
 */
function getPaymentStatus(PDO $pdo, int $payment_id, float $amount_due): array
{
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE 
                    WHEN transaction_type = 'wpłata' THEN amount
                    WHEN transaction_type = 'zwrot'  THEN -amount
                    ELSE 0 
                END) AS amount_paid,
            MAX(transaction_date) AS last_payment_date
        FROM payment_transactions
        WHERE payment_id = :pid AND status = 'zaksięgowana'
    ");
    $stmt->execute([':pid' => $payment_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $amount_paid = (float)($row['amount_paid'] ?? 0);
    $diff        = $amount_paid - $amount_due;

    if ($amount_paid <= 0) {
        $status = 'Nieopłacone';
    } elseif ($diff >= 0 && abs($diff) < 0.01) {
        $status = 'Opłacone';
    } elseif ($diff > 0) {
        $status = 'Nadpłata ' . number_format($diff, 2) . ' zł';
    } else {
        $status = 'Niedopłata ' . number_format(abs($diff), 2) . ' zł';
    }

    return [
        'status'           => $status,
        'amount_paid'      => $amount_paid,
        'diff'             => $diff,
        'last_payment_date'=> $row['last_payment_date'] ?? null
    ];
}

/* ==============================
   Checkout link — po tokenie
   ============================== */

/** NOWA: wysyłka linku po KONKRETNYM checkout_token */
function sendAutoReplyCheckoutWithToken(
    PDO $pdo,
    int $client_id,
    string $checkout_token,
    string $product_name,
    int $qty,
    float $price,
    int $owner_id
): void {
    $checkout_url = "https://olaj.pl/checkout/index.php?token=" . urlencode($checkout_token);

    $text = "✅ Dodano produkt: $product_name x$qty za " .
            number_format($price * $qty, 2, ',', ' ') .
            " zł.\nKliknij poniżej, aby przejść do koszyka:";

    sendStructuredMessage($pdo, $owner_id, $client_id, $text, [[
        'type'  => 'web_url',
        'url'   => $checkout_url,
        'title' => '🛒 Zobacz koszyk'
    ]]);
}

/** Legacy: zostawiona dla kompatybilności — wybiera „ostatni token po kliencie”. */
function sendAutoReplyCheckout(PDO $pdo, int $client_id, string $product_name, int $qty, float $price, int $owner_id): void
{
    @file_put_contents(__DIR__ . '/../logs/webhook_log.txt',
        "⚠️ sendAutoReplyCheckout (legacy) — użyj sendAutoReplyCheckoutWithToken, bo ta wersja wybiera ostatnią grupę po kliencie.\n",
        FILE_APPEND
    );

    $stmt = $pdo->prepare("
        SELECT og.checkout_token
        FROM order_groups og
        JOIN orders o ON og.order_id = o.id
        WHERE o.client_id = :client_id
        ORDER BY og.id DESC
        LIMIT 1
    ");
    $stmt->execute(['client_id' => $client_id]);
    $checkout_token = $stmt->fetchColumn();

    if (!$checkout_token) {
        @file_put_contents(__DIR__ . '/../logs/webhook_log.txt', "❌ Brak checkout_token dla klienta $client_id\n", FILE_APPEND);
        return;
    }

    sendAutoReplyCheckoutWithToken($pdo, $client_id, (string)$checkout_token, $product_name, $qty, $price, $owner_id);
}

/* ==============================
   Grupy: wybór po updated_at
   ============================== */

function columnExists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        LIMIT 1
    ");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}

/**
 * Zwraca [order_id, group_id, checkout_token].
 * Zasada: ostatnia grupa klienta po updated_at (fallback: created_at/id).
 * Jeśli og.checkout_completed = 1 → tworzy nową grupę i zwraca jej token.
 */
function resolveClientGroupByUpdatedAt(PDO $pdo, int $owner_id, int $client_id): array
{
    $hasOgUpdated   = columnExists($pdo, 'order_groups', 'updated_at');
    $hasOgCompleted = columnExists($pdo, 'order_groups', 'checkout_completed');

    // 1) Zamówienie klienta (ostatnie)
    $selO = $pdo->prepare("
        SELECT id, order_status
        FROM orders
        WHERE owner_id = :own AND client_id = :cid
        ORDER BY id DESC
        LIMIT 1
    ");
    $selO->execute([':own' => $owner_id, ':cid' => $client_id]);
    $order = $selO->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        // brak zamówienia → stwórz order + pierwszą grupę
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO orders (owner_id, client_id, order_status, created_at)
                           VALUES (:own,:cid,'otwarta_paczka:add_products',NOW())")
                ->execute([':own' => $owner_id, ':cid' => $client_id]);
            $order_id = (int)$pdo->lastInsertId();

            $token = generateCheckoutToken();
            $pdo->prepare("INSERT INTO order_groups (order_id, checkout_token, created_at)
                           VALUES (:oid,:t,NOW())")
                ->execute([':oid' => $order_id, ':t' => $token]);

            $gid = (int)$pdo->lastInsertId();
            $pdo->commit();
            return [$order_id, $gid, $token];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    $order_id = (int)$order['id'];

    // 2) Ostatnia grupa klienta
    $orderExpr = $hasOgUpdated ? "GREATEST(COALESCE(og.updated_at, og.created_at), og.created_at)" : "og.id";
    $q = $pdo->prepare("
        SELECT
            og.id,
            og.checkout_token,
            " . ($hasOgCompleted ? "COALESCE(og.checkout_completed,0)" : "0") . " AS completed
        FROM order_groups og
        JOIN orders o ON o.id = og.order_id
        WHERE o.owner_id = :own AND o.client_id = :cid
        ORDER BY $orderExpr DESC
        LIMIT 1
    ");
    $q->execute([':own' => $owner_id, ':cid' => $client_id]);
    $g = $q->fetch(PDO::FETCH_ASSOC);

    // 3) Brak grupy lub completed=1 → nowa
    if (!$g || (int)$g['completed'] === 1) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE orders SET order_status='otwarta_paczka:add_products', updated_at=NOW() WHERE id=:id")
                ->execute([':id' => $order_id]);

            $token = generateCheckoutToken();
            $pdo->prepare("INSERT INTO order_groups (order_id, checkout_token, created_at)
                           VALUES (:oid,:t,NOW())")
                ->execute([':oid' => $order_id, ':t' => $token]);
            $gid = (int)$pdo->lastInsertId();

            $pdo->commit();
            return [$order_id, $gid, $token];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    return [$order_id, (int)$g['id'], (string)$g['checkout_token']];
}

/** Po udanym dodaniu pozycji „dotknij” updated_at grupy, żeby zawsze była ostatnia. */
function touchGroupUpdatedAt(PDO $pdo, string $checkout_token): void
{
    if (!columnExists($pdo, 'order_groups', 'updated_at')) return;
    $pdo->prepare("UPDATE order_groups SET updated_at = NOW() WHERE checkout_token = :t LIMIT 1")
        ->execute([':t' => $checkout_token]);
}
if (!function_exists('getActiveTokenForClient')) {
    /**
     * Zwraca [order_id, group_id, checkout_token] dla otwartej paczki klienta.
     * Jeśli brak – tworzy order (status 'otwarta_paczka:add_products') + nową group z tokenem.
     */
    function getActiveTokenForClient(PDO $pdo, int $owner_id, int $client_id): array {
        // spróbuj znaleźć istniejącą otwartą paczkę
        $q = $pdo->prepare("
            SELECT o.id AS order_id, og.id AS group_id, og.checkout_token
            FROM orders o
            JOIN order_groups og ON og.order_id = o.id
            WHERE o.owner_id = :own
              AND o.client_id = :cid
              AND o.order_status LIKE 'otwarta_paczka%'
              AND og.checkout_token <> '' AND og.checkout_token IS NOT NULL
            ORDER BY og.id DESC
            LIMIT 1
        ");
        $q->execute([':own'=>$owner_id, ':cid'=>$client_id]);
        if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            return [(int)$row['order_id'], (int)$row['group_id'], (string)$row['checkout_token']];
        }

        // utwórz nową paczkę
        $pdo->beginTransaction();
        try {
            // ostatni order albo nowy
            $sel = $pdo->prepare("SELECT id FROM orders WHERE owner_id=:own AND client_id=:cid ORDER BY id DESC LIMIT 1");
            $sel->execute([':own'=>$owner_id, ':cid'=>$client_id]);
            $order_id = (int)($sel->fetchColumn() ?: 0);

            if (!$order_id) {
                $pdo->prepare("
                    INSERT INTO orders (owner_id, client_id, order_status, created_at)
                    VALUES (:own,:cid,'otwarta_paczka:add_products',NOW())
                ")->execute([':own'=>$owner_id, ':cid'=>$client_id]);
                $order_id = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare("UPDATE orders SET order_status='otwarta_paczka:add_products', updated_at=NOW() WHERE id=:id")
                    ->execute([':id'=>$order_id]);
            }

            $token = generateCheckoutToken();
            $pdo->prepare("
                INSERT INTO order_groups (order_id, checkout_token, created_at)
                VALUES (:oid,:t,NOW())
            ")->execute([':oid'=>$order_id, ':t'=>$token]);
            $group_id = (int)$pdo->lastInsertId();

            $pdo->commit();
            return [$order_id, $group_id, $token];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}


if (!function_exists('addItemByToken')) {
    function addItemByToken(
        PDO $pdo,
        string $checkout_token,
        int $product_id,
        int $qty,
        float $unit_price,
        string $source = 'manual',
        ?string $custom_name = null
    ): void {
        // znajdź group + order
        $stmt = $pdo->prepare("
            SELECT og.id AS group_id, og.order_id
            FROM order_groups og
            WHERE og.checkout_token = :t
            LIMIT 1
        ");
        $stmt->execute([':t' => $checkout_token]);
        $grp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$grp) {
            throw new RuntimeException('Brak grupy dla podanego tokenu.');
        }
        $order_group_id = (int)$grp['group_id'];
        $order_id       = (int)$grp['order_id'];

        // INSERT do order_items z poprawnym ENUM source
        $stmt = $pdo->prepare("
            INSERT INTO order_items
                (order_group_id, product_id, unit_price, custom_name, quantity, price, source, created_at)
            VALUES
                (:gid, :pid, :uprice, :cname, :qty, :price, :src, NOW())
        ");
        $stmt->execute([
            ':gid'   => $order_group_id,
            ':pid'   => $product_id,
            ':uprice'=> $unit_price,
            ':cname' => $custom_name,
            ':qty'   => $qty,
            ':price' => $unit_price * $qty,
            ':src'   => mapPlatformToSource($source, false), // mapujemy na ENUM
        ]);

        // rezerwacja stanów
        $pdo->prepare("UPDATE products SET stock_reserved = stock_reserved + :q WHERE id = :pid")
            ->execute([':q' => $qty, ':pid' => $product_id]);

        // stock_movements (zgodnie ze schematem)
        $pdo->prepare("
            INSERT INTO stock_movements (product_id, movement_type, quantity, order_id, note, created_at)
            VALUES (:pid, 'sprzedaż', :qneg, :oidr, 'parser:add', NOW())
        ")->execute([
            ':pid'  => $product_id,
            ':qneg' => -$qty,
            ':oidr' => $order_id,
        ]);

        // przelicz suma grupy
        $sum = (float)$pdo->query("SELECT SUM(quantity * unit_price) FROM order_items WHERE order_group_id = ".(int)$order_group_id)->fetchColumn();

        // payments: update/insert szkicu
        $stmt = $pdo->prepare("SELECT id FROM payments WHERE order_group_id = :gid LIMIT 1");
        $stmt->execute([':gid' => $order_group_id]);
        $pay_id = $stmt->fetchColumn();

        if ($pay_id) {
            $pdo->prepare("UPDATE payments SET amount=:amt, status='oczekujące', updated_at=NOW() WHERE id=:id")
                ->execute([':amt'=>$sum, ':id'=>$pay_id]);
        } else {
            $pdo->prepare("
                INSERT INTO payments
                    (order_id, order_group_id, currency, amount, status, is_test, checkout_token, created_at)
                VALUES (:oid, :gid, 'PLN', :amt, 'oczekujące', 0, :token, NOW())
            ")->execute([
                ':oid'   => $order_id,
                ':gid'   => $order_group_id,
                ':amt'   => $sum,
                ':token' => $checkout_token,
            ]);
        }

        // (opcjonalnie) dotknij updated_at grupy
        try { $pdo->prepare("UPDATE order_groups SET updated_at=NOW() WHERE id=:gid")->execute([':gid'=>$order_group_id]); } catch (\Throwable $e) {}
    }
}

if (!function_exists('mapPlatformToSource')) {
    /**
     * Mapuje platformę/kanał na dozwoloną wartość ENUM `source`
     * 
     * @param string|null $platform   Np. 'facebook', 'messenger', 'shop', 'store', 'chat', 'manual'
     * @param bool $is_comment        Jeśli wiadomość pochodzi z komentarza (np. Facebook Live)
     * @return string                 Poprawna wartość ENUM `source`
     */
    function mapPlatformToSource(?string $platform, bool $is_comment = false): string
    {
        // Domyślny fallback
        $default = 'manual';

        if (!$platform) {
            return $default;
        }

        $platform = strtolower(trim($platform));

        // Mapowanie
        $map = [
            'facebook'       => $is_comment ? 'facebook_comment' : 'messenger',
            'messenger'      => 'messenger',
            'messenger_api'  => 'messenger',
            'live'           => 'live',
            'chat'           => 'chat',
            'mobile'         => 'mobile',
            'manual'         => 'manual',
            'sklep'          => 'sklep',
            'shop'           => 'sklep',
            'store'          => 'sklep'
        ];

        return $map[$platform] ?? $default;
    }
}
