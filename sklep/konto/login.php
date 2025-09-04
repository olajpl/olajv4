<?php
// konto/login.php — logowanie + trwałe cookie + MERGE koszyka (V4)

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php'; // setClientAuth(), autoLoginFromCookie()
require_once __DIR__ . '/../includes/helpers.php';

// ---------- CSRF + OWNER ----------
if (empty($_SESSION['csrf_konto'])) {
    $_SESSION['csrf_konto'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_konto'];

$ownerId = (int)($_SESSION['owner_id'] ?? 1);
$_SESSION['owner_id'] = $ownerId;

// ---------- Utils ----------
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}
function getCartSessionId(): string
{
    if (empty($_COOKIE['cart_sid']) || !preg_match('/^[a-f0-9]{32}$/', (string)$_COOKIE['cart_sid'])) {
        $sid = bin2hex(random_bytes(16));
        setcookie('cart_sid', $sid, time() + 60 * 60 * 24 * 30, '/', '', is_https(), true);
        $_COOKIE['cart_sid'] = $sid;
    }
    return (string)$_COOKIE['cart_sid'];
}
function normalize_phone(string $raw): string
{
    return preg_replace('/\D+/', '', mb_strtolower(trim($raw)));
}
function safe_redirect_path(string $raw, string $fallback = '/cart/index.php'): string
{
    $raw = trim($raw);
    if ($raw === '' || $raw[0] !== '/') return $fallback;
    return str_replace(["\r", "\n"], '', $raw);
}

// ---------- GET: formularz ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
?>
    <!doctype html>
    <html lang="pl">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Zaloguj się</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>

    <body class="bg-gradient-to-br from-indigo-500 via-purple-500 to-pink-500 min-h-screen flex items-center justify-center p-4">
        <form method="POST" class="w-full max-w-sm bg-white/90 backdrop-blur rounded-2xl shadow-xl p-6 space-y-4 animate-fadeIn">
            <div class="text-center">
                <svg class="mx-auto h-12 w-12 text-indigo-600 animate-pulse" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 11c0-1.104.896-2 2-2s2 .896 2 2-.896 2-2 2-2-.896-2-2zm0 0c0-1.104-.896-2-2-2s-2 .896-2 2 .896 2 2 2 2-.896 2-2zm0 0v2m0 4h.01" />
                </svg>
                <h1 class="text-2xl font-bold text-gray-800 mt-2">Odzyskaj dostęp</h1>
                <p class="text-sm text-gray-500">Podaj e-mail lub numer telefonu</p>
            </div>

            <?php if (!empty($_SESSION['login_error'])): ?>
                <div class="p-2 text-sm rounded bg-red-50 text-red-700 border border-red-200">
                    <?= htmlspecialchars((string)$_SESSION['login_error']);
                    unset($_SESSION['login_error']); ?>
                </div>
            <?php endif; ?>

            <input type="email" name="email" placeholder="E-mail (opcjonalnie)" class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-indigo-400">
            <input type="tel" name="phone" placeholder="Telefon (opcjonalnie)" class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-indigo-400">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
            <input type="hidden" name="redirect" value="/cart/index.php">
            <button class="w-full bg-indigo-600 hover:bg-indigo-700 text-white rounded px-4 py-2 font-semibold shadow-lg transform hover:scale-[1.02] transition">
                Dalej
            </button>
            <p class="text-xs text-gray-500 text-center">Podaj przynajmniej jedno: e-mail lub telefon.</p>
        </form>
        <style>
            @keyframes fadeIn {
                from {
                    opacity: 0;
                    transform: translateY(10px)
                }

                to {
                    opacity: 1;
                    transform: translateY(0)
                }
            }

            .animate-fadeIn {
                animation: fadeIn 0.6s ease-out;
            }
        </style>
    </body>

    </html>
<?php
    exit;
}

// ---------- POST: logowanie + merge koszyka ----------
$csrf     = (string)($_POST['csrf'] ?? '');
$emailRaw = (string)($_POST['email'] ?? '');
$phoneRaw = (string)($_POST['phone'] ?? '');
$redirect = safe_redirect_path((string)($_POST['redirect'] ?? '/cart/index.php'));

if (!hash_equals($CSRF, $csrf)) {
    http_response_code(403);
    exit('Niepoprawny token bezpieczeństwa.');
}

$email = '';
$phone = '';
if (trim($emailRaw) !== '') $email = mb_strtolower(trim($emailRaw));
if (trim($phoneRaw) !== '') $phone = normalize_phone($phoneRaw);

if ($email === '' && $phone === '') {
    $_SESSION['login_error'] = 'Podaj e-mail lub numer telefonu.';
    header('Location: ' . $redirect);
    exit;
}

// Szukaj klienta (owner-scoped)
$conds  = [];
$params = [':owner' => $ownerId];
if ($email !== '') {
    $conds[] = 'LOWER(email) = :email';
    $params[':email'] = $email;
}
if ($phone !== '') {
    $conds[] = "REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+','') = :phone";
    $params[':phone'] = $phone;
}

$sql = "SELECT id, token, owner_id FROM clients WHERE owner_id = :owner AND (" . implode(' OR ', $conds) . ") LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    $_SESSION['login_error'] = 'Nie znaleziono klienta dla podanych danych.';
    header('Location: ' . $redirect);
    exit;
}

$clientId    = (int)$client['id'];
$clientToken = (string)$client['token'];

// 1) Sesja + trwałe cookie (30 dni)
session_regenerate_id(true);
setClientAuth([
    'id'       => $clientId,
    'token'    => $clientToken,
    'owner_id' => (int)$client['owner_id'],
]);

// 2) MERGE koszyka: cart_sid/session -> cart_items(client_token)
$sessionId = getCartSessionId();

// a) z DB (po session_id)
$sel = $pdo->prepare("
  SELECT product_id, quantity
  FROM cart_items
  WHERE owner_id = ? AND session_id = ? AND quantity > 0
");
$sel->execute([$ownerId, $sessionId]);
$dbRows = $sel->fetchAll(PDO::FETCH_ASSOC);

// b) z sesji PHP (legacy)
$sessionRows = [];
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        $qty = max(1, (int)($row['quantity'] ?? 1));
        if ($pid > 0) $sessionRows[] = ['product_id' => $pid, 'quantity' => $qty];
    }
}

// c) zsumuj po product_id
$rowsByPid = [];
foreach (array_merge($dbRows, $sessionRows) as $r) {
    $pid = (int)$r['product_id'];
    $qty = max(1, (int)$r['quantity']);
    if (!isset($rowsByPid[$pid])) $rowsByPid[$pid] = 0;
    $rowsByPid[$pid] += $qty;
}

// d) UPSERT do cart_items pod client_token
if ($rowsByPid) {
    $ins = $pdo->prepare("
      INSERT INTO cart_items (owner_id, client_token, session_id, product_id, quantity, unit_price, weight_kg, created_at, updated_at)
      VALUES (:o, :t, NULL, :p, :q, :price, :w, NOW(), NOW())
      ON DUPLICATE KEY UPDATE
        quantity   = quantity + VALUES(quantity),
        unit_price = VALUES(unit_price),
        weight_kg  = VALUES(weight_kg),
        updated_at = NOW()
    ");

    $get = $pdo->prepare("
      SELECT price AS unit_price, weight AS weight_kg
      FROM products
      WHERE id = ? AND owner_id = ? AND active = 1
      LIMIT 1
    ");

    $pdo->beginTransaction();
    foreach ($rowsByPid as $pid => $qty) {
        $get->execute([(int)$pid, $ownerId]);
        $snap = $get->fetch(PDO::FETCH_ASSOC);
        if (!$snap) continue;

        $ins->execute([
            ':o'     => $ownerId,
            ':t'     => $clientToken,
            ':p'     => (int)$pid,
            ':q'     => (int)$qty,
            ':price' => (float)($snap['unit_price'] ?? 0),
            ':w'     => isset($snap['weight_kg']) ? (float)$snap['weight_kg'] : null,
        ]);
    }
    // czyść po gościu
    $del = $pdo->prepare("DELETE FROM cart_items WHERE owner_id = ? AND session_id = ?");
    $del->execute([$ownerId, $sessionId]);
    unset($_SESSION['cart']);
    $pdo->commit();
}

// 3) Redirect — po prostu do bezpiecznej ścieżki (bez kombinacji z tokenami)
header('Location: ' . $redirect);
exit;
