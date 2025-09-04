<?php
// konto/register.php — rejestracja V4 (z dopięciem do client_platform_ids)
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (empty($_SESSION['csrf_konto'])) {
    $_SESSION['csrf_konto'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_konto'];
$ownerId = (int)(function_exists('getOwnerId') ? getOwnerId() : ($_SESSION['owner_id'] ?? 1));
$_SESSION['owner_id'] = $ownerId;

function normalize_phone(string $raw): string
{
    return preg_replace('/\D+/', '', mb_strtolower(trim($raw)));
}
function safe_redirect_path(string $raw, string $fallback = '/cart/index.php'): string
{
    $raw = trim((string)$raw);
    if ($raw === '' || $raw[0] !== '/') return $fallback;
    return str_replace(["\r", "\n"], '', $raw);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $redirectGet = safe_redirect_path((string)($_GET['redirect'] ?? '/cart/index.php'));
?>
    <!doctype html>
    <html lang="pl">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Rejestracja</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>

    <body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
        <form method="POST" class="w-full max-w-sm bg-white rounded-2xl shadow p-4 space-y-3" autocomplete="on">
            <h1 class="text-lg font-bold">Rejestracja</h1>
            <?php if (!empty($_SESSION['register_error'])): ?>
                <div class="p-2 text-sm rounded bg-red-50 text-red-700 border border-red-200">
                    <?= htmlspecialchars((string)$_SESSION['register_error']) ?>
                </div>
                <?php unset($_SESSION['register_error']); ?>
            <?php endif; ?>
            <input type="text" name="name" placeholder="Imię" class="w-full border rounded px-3 py-2" required>
            <input type="email" name="email" placeholder="E-mail (opcjonalnie)" class="w-full border rounded px-3 py-2">
            <input type="tel" name="phone" placeholder="Telefon (opcjonalnie)" class="w-full border rounded px-3 py-2">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectGet) ?>">
            <button class="w-full bg-indigo-600 hover:bg-indigo-700 text-white rounded px-4 py-2">Zarejestruj</button>
        </form>
    </body>

    </html>
<?php
    exit;
}

// ---------- POST ----------
$csrf     = (string)($_POST['csrf'] ?? '');
$name     = trim((string)($_POST['name'] ?? ''));
$emailRaw = (string)($_POST['email'] ?? '');
$phoneRaw = (string)($_POST['phone'] ?? '');
$redirect = safe_redirect_path((string)($_POST['redirect'] ?? '/cart/index.php'));

if (!hash_equals($CSRF, $csrf)) {
    http_response_code(403);
    exit('Niepoprawny token bezpieczeństwa.');
}

$email = $emailRaw !== '' ? mb_strtolower(trim($emailRaw)) : '';
$phone = $phoneRaw !== '' ? normalize_phone($phoneRaw) : '';

$errors = [];
if ($name === '') $errors[] = 'Podaj imię.';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy e-mail.';
if ($email === '' && $phone === '') $errors[] = 'Podaj e-mail lub numer telefonu.';
if (!empty($phone) && strlen($phone) < 6) $errors[] = 'Telefon wygląda za krótko.';

if ($errors) {
    $_SESSION['register_error'] = implode(' ', $errors);
    header('Location: /konto/register.php?redirect=' . urlencode($redirect));
    exit;
}

try {
    $conds = [];
    $params = [':owner' => $ownerId];
    if ($email !== '') {
        $conds[] = 'LOWER(email) = :email';
        $params[':email'] = $email;
    }
    if ($phone !== '') {
        $conds[] = "REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+','') = :phone";
        $params[':phone'] = $phone;
    }

    $existing = null;
    if ($conds) {
        $sql = "SELECT id, token, owner_id, COALESCE(NULLIF(name,''), '') AS name FROM clients
                WHERE owner_id = :owner AND (" . implode(' OR ', $conds) . ") LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($existing) {
        $clientId    = (int)$existing['id'];
        $clientToken = (string)$existing['token'];
        if ($name !== '' && ($existing['name'] ?? '') === '') {
            $upd = $pdo->prepare("UPDATE clients SET name = :n WHERE id = :id LIMIT 1");
            $upd->execute([':n' => $name, ':id' => $clientId]);
        }
    } else {
        $clientToken = 'olaj-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare("INSERT INTO clients (owner_id, name, email, phone, token, registered_at)
                               VALUES (:owner, :name, :email, :phone, :token, NOW())");
        $stmt->execute([
            ':owner' => $ownerId,
            ':name'  => $name,
            ':email' => $email,
            ':phone' => $phone,
            ':token' => $clientToken,
        ]);
        $clientId = (int)$pdo->lastInsertId();
    }

    session_regenerate_id(true);
    setClientAuth([
        'id'       => $clientId,
        'token'    => $clientToken,
        'owner_id' => $ownerId,
    ]);

    // 🔥 Dopisanie platformy
    $meta = [
        'token' => $clientToken,
        'ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
    ];
    $stmt = $pdo->prepare("
    INSERT IGNORE INTO client_platform_ids (owner_id, client_id, platform, platform_user_id, metadata, created_at)
    VALUES (:o, :cid, 'shop', :uid, :meta, NOW())
");
    $stmt->execute([
        ':o'    => $ownerId,
        ':cid'  => $clientId,
        ':uid'  => $clientToken,
        ':meta' => json_encode($meta),
    ]);


    header('Location: ' . $redirect);
    exit;
} catch (Throwable $e) {
    logg('error', 'auth.register', 'exception', ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    $_SESSION['register_error'] = 'Błąd rejestracji. Spróbuj ponownie.';
    header('Location: /konto/register.php?redirect=' . urlencode($redirect));
    exit;
}
