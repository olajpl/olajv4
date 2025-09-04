<?php
// auth/index.php – formularz rejestracji/logowania klienta Olaj.pl (V4)
// 1) Boot zależności (DB, helpery, logger)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../../includes/log.php'; // olaj_v4_logger – ważne: katalog Log z dużej litery!

// 2) Bezpieczna sesja (przed session_start)
$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('olaj_sess');
session_start();

// 3) Stałe/ustawienia
$owner_id = 1;
$redirect = $_GET['redirect'] ?? '/index.php';

// 4) CSRF (tworzenie)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_check(?string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

// 5) Helpery ciasteczek/tokenów
function setClientTokenCookie(string $token): void {
    $expire = time() + 60 * 60 * 24 * 365; // 365 dni
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    // Uwaga: zostawiamy HttpOnly=false, bo front może chcieć odczytać token (mobile fallback),
    // ale SameSite=Lax + ścieżka root.
    setcookie('client_token', $token, [
        'expires'  => $expire,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

function goWithToken(string $redirect, string $token): void {
    // 1) Cookie
    setClientTokenCookie($token);

    // 2) Log
    wlog("auth.success token set & redirect");

    // 3) Fallback: localStorage + redirect z paramem 'token'
    $redir = $redirect ?: '/index.php';
    $qs = strpos($redir, '?') === false ? '?' : '&';
    $url = $redir . $qs . 'token=' . rawurlencode($token);

    // 4) Wyrzucamy minimalny HTML, który zadziała na mobile
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html lang='pl'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1' /></head><body>
<script>
try {
  localStorage.setItem('client_token', " . json_encode($token) . ");
} catch(e) {}
location.replace(" . json_encode($url) . ");
</script>
Nosimy Cię do koszyka…</body></html>";
    exit;
}

// 6) Nagłówki bezpieczeństwa (lekko)
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// 7) Obsługa POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!csrf_check($csrf)) {
        $_SESSION['error'] = 'Nieprawidłowy token bezpieczeństwa (CSRF). Spróbuj ponownie.';
        logg('warning', 'auth', 'csrf_failed', ['ip'=>$_SERVER['REMOTE_ADDR'] ?? null]);
        header("Location: index.php?redirect=" . urlencode($redirect));
        exit;
    }

    // 7a) Logowanie tokenem
    if (isset($_POST['token_login'])) {
        $token = trim((string)($_POST['token'] ?? ''));
        try {
            $stmt = $pdo->prepare("SELECT id,email FROM clients WHERE token = ? AND owner_id = ? LIMIT 1");
            $stmt->execute([$token, $owner_id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($client) {
                logg('info', 'auth', 'token_login_ok', ['owner_id'=>$owner_id, 'client_id'=>$client['id'] ?? null]);
                goWithToken($redirect, $token);
            } else {
                $_SESSION['error'] = 'Niepoprawny token klienta.';
                logg('warning', 'auth', 'token_login_fail', ['owner_id'=>$owner_id]);
            }
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Błąd logowania tokenem.';
            logg('error', 'auth', 'token_login_exc', ['err'=>$e->getMessage()]);
        }
        header("Location: index.php?redirect=" . urlencode($redirect));
        exit;
    }

    // 7b) Odzysk tokenu po e-mailu
    if (isset($_POST['recover_token'])) {
        $email = trim((string)($_POST['recover_email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Podaj poprawny adres e‑mail.';
            header("Location: index.php?redirect=" . urlencode($redirect));
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT token,id FROM clients WHERE email = ? AND owner_id = ? LIMIT 1");
            $stmt->execute([$email, $owner_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['token'])) {
                logg('info', 'auth', 'recover_ok', ['owner_id'=>$owner_id, 'client_id'=>$row['id']]);
                goWithToken($redirect, $row['token']);
            } else {
                $_SESSION['error'] = 'Nie znaleziono klienta z tym adresem e‑mail.';
                logg('warning', 'auth', 'recover_not_found', ['email'=>$email, 'owner_id'=>$owner_id]);
            }
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Błąd podczas odzyskiwania tokenu.';
            logg('error', 'auth', 'recover_exc', ['err'=>$e->getMessage()]);
        }
        header("Location: index.php?redirect=" . urlencode($redirect));
        exit;
    }

    // 7c) Rejestracja
    $name    = trim((string)($_POST['name'] ?? ''));
    $surname = trim((string)($_POST['surname'] ?? ''));
    $email   = trim((string)($_POST['email'] ?? ''));
    $prefix  = (string)($_POST['prefix'] ?? 'olaj');

    if ($name === '' || $surname === '' || $email === '') {
        $_SESSION['error'] = 'Wszystkie pola są wymagane.';
        header("Location: index.php?redirect=" . urlencode($redirect));
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Podaj poprawny adres e‑mail.';
        header("Location: index.php?redirect=" . urlencode($redirect));
        exit;
    }

    try {
        // unikalność per owner
        $stmt = $pdo->prepare("SELECT id, token FROM clients WHERE email = ? AND owner_id = ? LIMIT 1");
        $stmt->execute([$email, $owner_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Jeśli już jest – nie frustrujemy usera, tylko od razu logujemy na istniejący token
            logg('info', 'auth', 'register_email_exists', ['owner_id'=>$owner_id, 'client_id'=>$existing['id']]);
            goWithToken($redirect, $existing['token']);
        }

        // wygeneruj token
        $token = generateToken($prefix); // np. olaj_xxx – nasz helper

        // insert
        $stmt = $pdo->prepare("
            INSERT INTO clients (name, surname, email, token, owner_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$name, $surname, $email, $token, $owner_id]);

        $clientId = (int)$pdo->lastInsertId();
        logg('info', 'auth', 'register_ok', ['owner_id'=>$owner_id, 'client_id'=>$clientId]);

        goWithToken($redirect, $token);
    } catch (Throwable $e) {
        $_SESSION['error'] = 'Coś poszło nie tak przy rejestracji. Spróbuj ponownie.';
        logg('error', 'auth', 'register_exc', ['err'=>$e->getMessage()]);
        header("Location: index.php?redirect=" . urlencode($redirect));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Zaloguj / Zarejestruj – Olaj.pl</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <style>
    .input { @apply w-full p-2 border rounded; }
    .btn   { @apply px-4 py-2 rounded text-white w-full; }
  </style>
</head>
<body class="bg-gray-100 text-gray-800">
  <div class="min-h-screen flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-xl bg-white shadow-lg p-6 rounded-2xl">
      <h1 class="text-2xl font-extrabold mb-2 text-center">Klient Olaj.pl</h1>
      <p class="text-center text-sm text-gray-500 mb-6">Szybka rejestracja lub logowanie tokenem. Bez spiny – w 30 sekund i do koszyka! 🛒</p>

      <?php if (!empty($_SESSION['error'])): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-2 rounded mb-4">
          <?= htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php unset($_SESSION['error']); ?>
      <?php endif; ?>

      <?php if (!empty($_SESSION['success'])): ?>
        <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-2 rounded mb-4">
          <?= htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php unset($_SESSION['success']); ?>
      <?php endif; ?>

      <!-- Rejestracja -->
      <form method="POST" class="mb-6 space-y-3">
        <input type="hidden" name="prefix" value="olaj">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm mb-1">Imię</label>
            <input type="text" name="name" required class="input" autocomplete="given-name" />
          </div>
          <div>
            <label class="block text-sm mb-1">Nazwisko</label>
            <input type="text" name="surname" required class="input" autocomplete="family-name" />
          </div>
        </div>

        <div>
          <label class="block text-sm mb-1">Adres e‑mail</label>
          <input type="email" name="email" required class="input" autocomplete="email" />
        </div>

        <button type="submit" class="btn bg-pink-600 hover:bg-pink-700">Zarejestruj się</button>
      </form>

      <hr class="my-6">

      <!-- Logowanie tokenem -->
      <h2 class="text-md font-semibold mb-2">🔐 Masz już token?</h2>
      <form method="POST" class="mb-6 space-y-3">
        <input type="hidden" name="token_login" value="1">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">

        <div>
          <label class="block text-sm mb-1">Twój token</label>
          <input type="text" name="token" placeholder="np. olaj_xxxxxxxx" class="input" required />
        </div>

        <button type="submit" class="btn bg-gray-800 hover:bg-black">Zaloguj przez token</button>
      </form>

      <!-- Odzysk tokenu -->
      <h2 class="text-md font-semibold mb-2">📧 Odzyskaj token przez e‑mail</h2>
      <form method="POST" class="space-y-3">
        <input type="hidden" name="recover_token" value="1">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">

        <div>
          <label class="block text-sm mb-1">Adres e‑mail</label>
          <input type="email" name="recover_email" placeholder="np. jan.kowalski@example.com" class="input" required />
        </div>

        <button type="submit" class="btn bg-blue-600 hover:bg-blue-700">Wyświetl mój token</button>
      </form>

      <!-- Narzędzia dev/awaryjne (tylko front) -->
      <details class="mt-6 text-xs text-gray-500">
        <summary class="cursor-pointer select-none">🔧 Problemy z logowaniem na telefonie? (narzędzia)</summary>
        <div class="mt-2 space-y-2">
          <button
            class="w-full border rounded px-3 py-2"
            onclick="try{ localStorage.removeItem('client_token'); alert('Wyczyszczono token z localStorage ✅'); }catch(e){}"
            type="button">Usuń token z localStorage</button>
          <button
            class="w-full border rounded px-3 py-2"
            onclick="document.cookie='client_token=; Max-Age=0; path=/'; alert('Wyczyszczono cookie client_token ✅');"
            type="button">Usuń cookie client_token</button>
        </div>
      </details>
    </div>
  </div>

  <!-- Mini mostek: jeśli przyszedł ?token= w URL – zapisz do localStorage i ustaw cookie -->
  <script>
  (function() {
    try {
      const params = new URLSearchParams(location.search);
      const t = params.get('token');
      if (t) {
        try { localStorage.setItem('client_token', t); } catch(e) {}
        // delikatna próba ustawienia ciasteczka z JS jeśli serwerowe nie weszło
        const d = new Date();
        d.setTime(d.getTime() + 365*24*60*60*1000);
        document.cookie = "client_token=" + encodeURIComponent(t) + "; expires=" + d.toUTCString() + "; path=/; SameSite=Lax";
      }
    } catch(e) {}
  })();
  </script>
</body>
</html>
