<?php
// konto/recover.php — odzyskanie dostępu po e-mailu/telefonie (V4, hard sessions, defensywne kolumny)

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/log.php';     // logg(), wlog()
require_once __DIR__ . '/../includes/auth.php';    // setClientAuth(), logoutClient(), autoLoginFromCookie()
require_once __DIR__ . '/../includes/helpers.php'; // getOwnerId(), itp.

// ---------- CSRF + OWNER ----------
if (empty($_SESSION['csrf_konto'])) {
    $_SESSION['csrf_konto'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_konto'];

$ownerId = (int)(function_exists('getOwnerId') ? getOwnerId() : ($_SESSION['owner_id'] ?? 1));
$_SESSION['owner_id'] = $ownerId;

// ---------- Utils ----------
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}
function normalize_phone(string $raw): string
{
    return preg_replace('/\D+/', '', mb_strtolower(trim($raw)));
}
function safe_redirect_path(string $raw, string $fallback = '/index.php'): string
{
    $raw = trim((string)$raw);
    if ($raw === '' || $raw[0] !== '/') return $fallback; // tylko ścieżki lokalne
    return str_replace(["\r", "\n"], '', $raw);
}
function getCartSessionId(): string
{
    if (!isset($_COOKIE['cart_sid']) || !preg_match('/^[a-f0-9]{32}$/', (string)$_COOKIE['cart_sid'])) {
        $sid = bin2hex(random_bytes(16));
        setcookie('cart_sid', $sid, time() + 60 * 60 * 24 * 30, '/', '', is_https(), true); // HttpOnly
        $_COOKIE['cart_sid'] = $sid;
    }
    return (string)$_COOKIE['cart_sid'];
}
function columnExists(PDO $pdo, string $table, string $col): bool
{
    $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $q->execute([$table, $col]);
    return (bool)$q->fetchColumn();
}
function hasColumn(PDO $pdo, string $table, string $column): bool
{ // alias
    return columnExists($pdo, $table, $column);
}
function detectProductColumns(PDO $pdo): array
{
    $priceCol  = hasColumn($pdo, 'products', 'unit_price') ? 'unit_price' : (hasColumn($pdo, 'products', 'price') ? 'price' : null);
    $weightCol = hasColumn($pdo, 'products', 'weight_kg') ? 'weight_kg' : (hasColumn($pdo, 'products', 'weight') ? 'weight' : null);
    return ['price' => $priceCol, 'weight' => $weightCol];
}
// Wykryj nazwy kolumn w cart_items (różne warianty schematu)
function detectCartCols(PDO $pdo): array
{
    $qtyCol   = columnExists($pdo, 'cart_items', 'quantity') ? 'quantity' : (columnExists($pdo, 'cart_items', 'qty') ? 'qty' : null);
    $sessCol  = columnExists($pdo, 'cart_items', 'session_id') ? 'session_id' : (columnExists($pdo, 'cart_items', 'cart_sid') ? 'cart_sid' : null);
    $bindBy   = columnExists($pdo, 'cart_items', 'client_token') ? 'client_token' : (columnExists($pdo, 'cart_items', 'client_id') ? 'client_id' : null);
    $priceCol = columnExists($pdo, 'cart_items', 'unit_price') ? 'unit_price' : (columnExists($pdo, 'cart_items', 'price') ? 'price' : null);
    $wCol     = columnExists($pdo, 'cart_items', 'weight_kg') ? 'weight_kg' : (columnExists($pdo, 'cart_items', 'weight') ? 'weight' : null);
    $source   = columnExists($pdo, 'cart_items', 'source') ? 'source' : null;

    return [
        'qty'     => $qtyCol,     // jeden z: quantity/qty
        'session' => $sessCol,    // jeden z: session_id/cart_sid
        'bind'    => $bindBy,     // jeden z: client_token/client_id
        'price'   => $priceCol,   // unit_price/price lub null
        'weight'  => $wCol,       // weight_kg/weight lub null
        'source'  => $source,     // 'source' jeśli istnieje
    ];
}

// Bezpieczny UPSERT do cart_items biorąc pod uwagę istniejące kolumny
function upsertCartItem(PDO $pdo, int $ownerId, string $bindCol, $bindVal, int $productId, float $qty, ?float $unitPrice, ?float $weightKg, array $cartCols): void
{
    $qtyCol   = $cartCols['qty'] ?? null;
    if (!$qtyCol) return; // w złym schemacie – nie ryzykuj

    // UPDATE
    $set = ["`$qtyCol` = `$qtyCol` + :q"];
    $params = [
        ':q'  => $qty,
        ':o'  => $ownerId,
        ':b'  => $bindVal,
        ':p'  => $productId,
    ];

    if (!is_null($cartCols['price'])) {
        $set[] = "`{$cartCols['price']}` = COALESCE(:up, `{$cartCols['price']}`)";
        $params[':up'] = $unitPrice;
    }
    if (!is_null($cartCols['weight'])) {
        $set[] = "`{$cartCols['weight']}` = COALESCE(:w, `{$cartCols['weight']}`)";
        $params[':w'] = $weightKg;
    }
    $updSql = "UPDATE cart_items SET " . implode(', ', $set) . ", updated_at = NOW()
               WHERE owner_id=:o AND `$bindCol`=:b AND product_id=:p";
    $upd = $pdo->prepare($updSql);
    $upd->execute($params);
    if ($upd->rowCount() > 0) return;

    // INSERT
    $cols = ['owner_id', 'product_id', $bindCol];
    $vals = [':o', ':p', ':b'];
    $insParams = [':o' => $ownerId, ':p' => $productId, ':b' => $bindVal];

    if (!is_null($cartCols['session'])) {
        // przy logowaniu wiążemy do klienta/klienta_tokenu, więc session null
        $cols[] = $cartCols['session'];
        $vals[] = 'NULL';
    }
    $cols[] = $qtyCol;
    $vals[] = ':q';
    $insParams[':q'] = $qty;

    if (!is_null($cartCols['price'])) {
        $cols[] = $cartCols['price'];
        $vals[] = ':up';
        $insParams[':up'] = $unitPrice;
    }
    if (!is_null($cartCols['weight'])) {
        $cols[] = $cartCols['weight'];
        $vals[] = ':w';
        $insParams[':w'] = $weightKg;
    }
    if (!is_null($cartCols['source'])) {
        $cols[] = $cartCols['source'];
        $vals[] = "'shop'";
    }

    // created_at/updated_at (jeśli są)
    if (columnExists($pdo, 'cart_items', 'created_at')) {
        $cols[] = 'created_at';
        $vals[] = 'NOW()';
    }
    if (columnExists($pdo, 'cart_items', 'updated_at')) {
        $cols[] = 'updated_at';
        $vals[] = 'NOW()';
    }

    $insSql = "INSERT INTO cart_items (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ")";
    $ins = $pdo->prepare($insSql);
    $ins->execute($insParams);
}

/* ========== GET: Formularz ========== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $redirectGet = safe_redirect_path((string)($_GET['redirect'] ?? '/index.php'));
?>
    <!doctype html>
    <html lang="pl">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Odzyskaj dostęp</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>

    <body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
        <form method="POST" class="w-full max-w-sm bg-white rounded-2xl shadow p-4 space-y-3">
            <h1 class="text-lg font-bold">Odzyskaj dostęp</h1>

            <?php if (!empty($_SESSION['login_error'])): ?>
                <div class="p-2 text-sm rounded bg-red-50 text-red-700 border border-red-200">
                    <?= htmlspecialchars((string)$_SESSION['login_error']) ?>
                </div>
                <?php unset($_SESSION['login_error']); ?>
            <?php endif; ?>

            <input type="email" name="email" placeholder="E-mail (opcjonalnie)" class="w-full border rounded px-3 py-2" inputmode="email" autocomplete="email">
            <input type="tel" name="phone" placeholder="Telefon (opcjonalnie)" class="w-full border rounded px-3 py-2" inputmode="tel" autocomplete="tel">

            <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectGet) ?>">

            <button class="w-full bg-indigo-600 hover:bg-indigo-700 text-white rounded px-4 py-2">Dalej</button>
            <p class="text-xs text-gray-500">Podaj przynajmniej jedno z pól: e-mail lub telefon.</p>
        </form>
    </body>

    </html>
<?php
    exit;
}

/* ========== POST: Logowanie + MERGE koszyka ========== */
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
    header('Location: /konto/recover.php?redirect=' . urlencode($redirect));
    exit;
}

try {
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

    $client = null;
    if ($conds) {
        $sql = "SELECT id, token, owner_id FROM clients WHERE owner_id = :owner AND (" . implode(' OR ', $conds) . ") LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$client) {
        $_SESSION['login_error'] = 'Nie znaleziono klienta dla podanych danych.';
        logg('warning', 'auth.recover', 'client_not_found', compact('ownerId', 'email', 'phone'));
        header('Location: /konto/recover.php?redirect=' . urlencode($redirect));
        exit;
    }

    $clientId    = (int)$client['id'];
    $clientToken = (string)$client['token'];

    // Auth + trwałe cookie
    session_regenerate_id(true);
    setClientAuth([
        'id'       => $clientId,
        'token'    => $clientToken,
        'owner_id' => (int)$client['owner_id'],
    ]);
    $stmt = $pdo->prepare("
    INSERT IGNORE INTO client_platform_ids (owner_id, client_id, platform, platform_user_id, created_at)
    VALUES (:o, :cid, 'shop', NULL, NOW())
");
    $stmt->execute([
        ':o'   => $ownerId,
        ':cid' => $clientId,
    ]);


    logg('info', 'auth.recover', 'client_authenticated', compact('ownerId', 'clientId', 'clientToken'));

    // MERGE koszyka:
    $sessionId = getCartSessionId();
    $cartCols  = detectCartCols($pdo);

    // a) odczyt koszyka gościa po session_id/cart_sid
    $dbRows = [];
    if ($cartCols['session'] && $cartCols['qty']) {
        $sel = $pdo->prepare("
            SELECT product_id, `{$cartCols['qty']}` AS quantity
            FROM cart_items
            WHERE owner_id = :o AND `{$cartCols['session']}` = :sid AND `{$cartCols['qty']}` > 0
        ");
        $sel->execute([':o' => $ownerId, ':sid' => $sessionId]);
        $dbRows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // b) legacy sesja PHP
    $sessionRows = [];
    if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $row) {
            $pid = (int)($row['product_id'] ?? 0);
            $qty = (float)max(1, (int)($row['quantity'] ?? $row['qty'] ?? 1));
            if ($pid > 0) $sessionRows[] = ['product_id' => $pid, 'quantity' => $qty];
        }
    }

    // c) sumowanie po product_id
    $rowsByPid = [];
    foreach (array_merge($dbRows, $sessionRows) as $r) {
        $pid = (int)$r['product_id'];
        $qty = (float)max(0, (float)($r['quantity'] ?? 0));
        if ($pid <= 0 || $qty <= 0) continue;
        if (!isset($rowsByPid[$pid])) $rowsByPid[$pid] = 0.0;
        $rowsByPid[$pid] += $qty;
    }

    if ($rowsByPid) {
        // snapshot z products
        $pcols = detectProductColumns($pdo);
        $priceExpr  = $pcols['price']  ? "`{$pcols['price']}`"   : "0";
        $weightExpr = $pcols['weight'] ? ", `{$pcols['weight']}` AS weight_kg" : ", NULL AS weight_kg";

        $sqlGet = "SELECT $priceExpr AS unit_price$weightExpr FROM products WHERE id = ? AND owner_id = ? LIMIT 1";
        $get = $pdo->prepare($sqlGet);

        // wiązanie po client_token lub client_id
        $bindCol = $cartCols['bind'] ?? 'client_token';
        $bindVal = ($bindCol === 'client_id') ? $clientId : $clientToken;

        foreach ($rowsByPid as $pid => $qty) {
            $get->execute([$pid, $ownerId]);
            $snap = $get->fetch(PDO::FETCH_ASSOC);
            if (!$snap) continue;

            upsertCartItem(
                $pdo,
                $ownerId,
                $bindCol,
                $bindVal,
                (int)$pid,
                (float)$qty,
                isset($snap['unit_price']) ? (float)$snap['unit_price'] : null,
                isset($snap['weight_kg']) ? (float)$snap['weight_kg'] : null,
                $cartCols
            );
        }

        // d) sprzątanie po gościu
        if ($cartCols['session']) {
            $del = $pdo->prepare("DELETE FROM cart_items WHERE owner_id = :o AND `{$cartCols['session']}` = :sid");
            $del->execute([':o' => $ownerId, ':sid' => $sessionId]);
        }
        unset($_SESSION['cart']);

        logg('info', 'auth.recover', 'cart_merged', [
            'ownerId'     => $ownerId,
            'clientId'    => $clientId,
            'dbRows'      => count($dbRows),
            'sessionRows' => count($sessionRows),
            'mergedItems' => count($rowsByPid),
            'bindBy'      => $bindCol,
        ]);
    } else {
        logg('info', 'auth.recover', 'cart_empty_or_already_bound', compact('ownerId', 'clientId'));
    }

    // Redirect — dokładamy token (przydatne dla /shop/moje.php)
    $path = $redirect;
    if ($path === '' || $path[0] !== '/') $path = '/';
    $sep = (strpos($path, '?') !== false) ? '&' : '?';
    header('Location: ' . $path . $sep . 'token=' . urlencode($clientToken));
    exit;
} catch (Throwable $e) {
    logg('error', 'auth.recover', 'exception', [
        'ownerId' => $ownerId,
        'msg'     => $e->getMessage(),
        'trace'   => $e->getTraceAsString(),
    ]);
    $_SESSION['login_error'] = 'Nie udało się odzyskać dostępu. Spróbuj ponownie.';
    header('Location: /konto/recover.php?redirect=' . urlencode($redirect));
    exit;
}
