<?php
// api/facebook/connect.php
// 1. opis czynności lub funkcji
// Callback z FB: weryfikuje state/CSRF, wymienia code->access_token, pobiera strony i zapisuje page_tokeny.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../includes/log.php';
require_once __DIR__ . '/../../includes/env.php';
$appId     = env('FB_APP_ID');
$appSecret = env('FB_APP_SECRET');
$redirect  = env('FB_REDIRECT_URI') ?: 'https://panel.olaj.pl/api/facebook/connect.php';

session_start(); // na wszelki wypadek, jeśli nie startnięte

// 2) Obsługa błędów z FB
if (isset($_GET['error'])) {
    logg('error', 'facebook.connect', '❌ FB error', ['error' => $_GET['error'], 'desc' => $_GET['error_description'] ?? '']);
    $_SESSION['success_message'] = "❌ Facebook zwrócił błąd: " . htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    header('Location: /admin/settings/integracje/');
    exit;
}

// 3) Walidacja params
$code  = $_GET['code']  ?? null;
$state = $_GET['state'] ?? null;
if (!$code || !$state) {
    echo "❌ Brak parametru code/state.";
    exit;
}
$decoded = json_decode(base64_decode($state), true);
if (!is_array($decoded) || empty($decoded['csrf']) || $decoded['csrf'] !== ($_SESSION['fb_csrf'] ?? '')) {
    echo "❌ Nieważny state/CSRF.";
    exit;
}
$owner_id = (int)($decoded['owner_id'] ?? 0);
if ($owner_id <= 0) {
    echo "❌ Brak owner_id w state.";
    exit;
}

// 4) Konfiguracja
$redirect  = getenv('FB_REDIRECT_URI') ?: 'https://panel.olaj.pl/api/facebook/connect.php';
$appId     = getenv('FB_APP_ID');
$appSecret = getenv('FB_APP_SECRET');


try {
    // 5) Wymiana code -> access_token
    $url = "https://graph.facebook.com/v18.0/oauth/access_token"
        . "?client_id={$appId}&redirect_uri=" . urlencode($redirect)
        . "&client_secret={$appSecret}&code={$code}";

    $resp = file_get_contents($url);
    if (!$resp) throw new Exception("Brak odpowiedzi z Graph /oauth/access_token");

    $data = json_decode($resp, true);
    $access_token = $data['access_token'] ?? null;
    if (!$access_token) throw new Exception("Brak access_token");

    // 6) Pobierz listę stron i ich page_tokeny
    $resp2 = file_get_contents("https://graph.facebook.com/v18.0/me/accounts?access_token={$access_token}");
    $pages = json_decode($resp2, true);

    if (empty($pages['data'])) throw new Exception("Brak stron w koncie FB");

    // 7) Zapis page_tokenów + app_secret do facebook_tokens
    $stmt = $pdo->prepare("
        INSERT INTO facebook_tokens (owner_id, page_id, page_name, page_token, app_secret, created_at)
        VALUES (:owner_id, :page_id, :page_name, :page_token, :app_secret, NOW())
        ON DUPLICATE KEY UPDATE page_name=VALUES(page_name), page_token=VALUES(page_token), app_secret=VALUES(app_secret)
    ");

    foreach ($pages['data'] as $p) {
        $stmt->execute([
            ':owner_id'   => $owner_id,
            ':page_id'    => $p['id'],
            ':page_name'  => $p['name'] ?? '',
            ':page_token' => $p['access_token'],
            ':app_secret' => $appSecret,
        ]);
    }

    // 8) Opcjonalnie zapisz wygodnie „domyślną” page_id do settings
    // (jeśli jedna strona – zapisz od razu)
    if (count($pages['data']) === 1) {
        require_once __DIR__ . '/../../includes/settings.php';
        set_setting($owner_id, 'facebook_page_id', $pages['data'][0]['id']);
    }

    logg('info', 'facebook.connect', '✅ FB connected', ['owner_id' => $owner_id, 'pages_count' => count($pages['data'])]);

    $_SESSION['success_message'] = "✅ Połączono z Facebookiem (stron: " . count($pages['data']) . ").";
    header('Location: /admin/settings/integracje/');
    exit;
} catch (Throwable $e) {
    logg('error', 'facebook.connect', '❌ Wyjątek przy łączeniu', ['err' => $e->getMessage()]);
    $_SESSION['success_message'] = "❌ Błąd łączenia: " . htmlspecialchars($e->getMessage());
    header('Location: /admin/settings/integracje/');
    exit;
}
