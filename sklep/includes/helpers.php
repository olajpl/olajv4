<?php
// includes/helpers.php – funkcje pomocnicze dla systemu Olaj.pl
function getOwnerId(): int
{
    return (int)($_SESSION['owner_id'] ?? APP_OWNER_ID ?? 1);
}

function format_price($amount, $currency = 'zł')
{
    return number_format($amount, 2, ',', ' ') . ' ' . $currency;
}

function generateToken($prefix = '', $length = 5)
{
    $random = bin2hex(random_bytes($length));
    return $prefix ? $prefix . '-' . $random : $random;
}
if (!function_exists('generateCheckoutToken')) {
    function generateCheckoutToken()
    {
        $raw = bin2hex(random_bytes(16)); // 32 znaki hex
        return vsprintf('%s-%s-%s-%s-%s-%s-%s-%s', str_split($raw, 4));
    }
}
function debug_log($message)
{
    $logFile = __DIR__ . '/../logs/debug.log';
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

function safe_input($input)
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}
function getClientByToken(string $token): ?array
{
    global $pdo;

    // 1) Bezpośredni client_token
    $st = $pdo->prepare("SELECT * FROM clients WHERE token = :t LIMIT 1");
    $st->execute([':t' => $token]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) return $row;

    // 2) checkout_token (orders) lub group_token (order_groups)
    $sql = "
    SELECT c.*
    FROM order_groups g
    JOIN orders o  ON o.id = g.order_id
    JOIN clients c ON c.id = o.client_id
    WHERE o.checkout_token = :tok1 OR g.group_token = :tok2
    LIMIT 1
";
$st = $pdo->prepare($sql);
$st->execute([
    ':tok1' => $token,
    ':tok2' => $token,
]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}


function getShopSettings(int $owner_id): array
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM shop_settings WHERE owner_id = ? LIMIT 1");
    $stmt->execute([$owner_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
function getCartSid(): string
{
    $sid = $_SESSION['cart_sid'] ?? $_COOKIE['cart_sid'] ?? '';
    if (!$sid || !preg_match('/^[a-f0-9]{32}$/', $sid)) {
        $sid = bin2hex(random_bytes(16));
        setcookie('cart_sid', $sid, time() + 60 * 60 * 24 * 30, '/', '', false, true);
        $_COOKIE['cart_sid'] = $sid;
    }
    $_SESSION['cart_sid'] = $sid;
    return $sid;
}
function migrateCartToClient(PDO $pdo, int $ownerId, string $cartSid, string $clientToken): void
{
    if (!$cartSid || !$clientToken) return;
    $stmt = $pdo->prepare("
        UPDATE cart_items
        SET client_token = :ct, session_id = NULL, updated_at = NOW()
        WHERE owner_id = :oid AND session_id = :sid
    ");
    $stmt->execute([
        'ct'  => $clientToken,
        'sid' => $cartSid,
        'oid' => $ownerId,
    ]);
}
if (!function_exists('fmt_weight')) {
    function fmt_weight(float $kg): string
    {
        if ($kg > 0 && $kg < 1) {
            return number_format($kg * 1000, 0, ',', ' ') . ' g';
        }
        return number_format($kg, 2, ',', ' ') . ' kg';
    }
}
