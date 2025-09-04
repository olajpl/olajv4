<?php
// admin/tools/furgo_services_check.php
declare(strict_types=1);

/**
 * Samodzielny tester REST Furgonetka:
 *  - ładuje bootstrap (PDO) z public_html/includes/*
 *  - bierze access_token z shipping_integrations
 *  - robi bezpośredni GET https://api.furgonetka.pl/account/services
 * Bez refleksji i bez wycieczek poza open_basedir.
 */

// === USTAWIENIA ŚCIEŻEK (open_basedir-safe) ===============================
$DOCROOT = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'); // zwykle .../public_html
if ($DOCROOT === '' || !is_dir($DOCROOT)) {
    // fallback: wylicz z położenia pliku (…/public_html/admin/tools)
    $DOCROOT = dirname(__DIR__, 2);
}

// Pomocnicze echo / błąd
function http_echo(string $s): void {
    if (PHP_SAPI === 'cli') {
        echo preg_replace('/<br\s*\/?>/i', PHP_EOL, $s) . PHP_EOL;
    } else {
        echo $s . "<br>";
    }
}
function fail(string $msg, int $code = 500): void {
    if (!headers_sent()) @http_response_code($code);
    http_echo("❌ " . $msg);
    exit(1);
}

// === ŁADOWANIE BOOTSTRAPU =================================================
$INC_DIR = $DOCROOT . '/includes';
foreach (['auth.php', 'db.php', 'helpers.php'] as $f) {
    $p = $INC_DIR . '/' . $f;
    if (!is_file($p)) fail("Brak pliku bootstrap: " . htmlspecialchars($p));
    require_once $p;
}

// === WALIDACJA PDO =========================================================
if (!isset($pdo) || !$pdo) {
    fail("Brak połączenia PDO (upewnij się, że bootstrap załadował DB).");
}

// === POBIERZ KONFIG Z shipping_integrations ================================
try {
    $stmt = $pdo->prepare("SELECT * FROM shipping_integrations WHERE broker='furgonetka' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fail("Błąd SQL przy odczycie shipping_integrations: " . $e->getMessage());
}

if (!$cfg) {
    fail("Brak konfiguracji Furgonetki w tabeli shipping_integrations.");
}

$accessToken = trim((string)($cfg['access_token'] ?? ''));
if ($accessToken === '') {
    fail("Brak access_token w shipping_integrations (uzupełnij OAuth).", 400);
}

// === PROSTE S2S CURL DO REST ==============================================
function furgo_get_json(string $url, string $bearer, array $extraHeaders = []): array {
    $ch = curl_init($url);
    $hdrs = array_merge([
        'Accept: application/json',
        'Authorization: Bearer ' . $bearer,
        'User-Agent: Olaj.pl V4 / furgo_services_check',
        // opcjonalnie: 'X-Language: pl_PL'
    ], $extraHeaders);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => $hdrs,
    ]);

    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("CURL error: " . ($err ?: 'brak treści'));
    }
    $json = json_decode($raw, true);
    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("HTTP $http; niepoprawny JSON: " . substr($raw, 0, 300));
    }
    if ($http < 200 || $http >= 300) {
        // spróbuj wyłuskać message/errors
        $msg = is_array($json) ? json_encode($json, JSON_UNESCAPED_UNICODE) : $raw;
        throw new RuntimeException("HTTP $http: " . $msg);
    }
    return is_array($json) ? $json : [];
}

// === TEST 1: GET /account/services ========================================
http_echo("ℹ️ Testuję Furgonetka REST: GET /account/services …");

try {
    $resp = furgo_get_json('https://api.furgonetka.pl/account/services', $accessToken);
    $services = $resp['services'] ?? [];
    $count = is_array($services) ? count($services) : 0;

    http_echo("✅ API OK — pobrano {$count} usług.");
    if ($count) {
        $preview = array_slice($services, 0, 5, true);
        if (PHP_SAPI === 'cli') {
            print_r($preview);
        } else {
            http_echo("<pre>" . htmlspecialchars(print_r($preview, true)) . "</pre>");
        }
    }
} catch (Throwable $e) {
    fail("API FAIL: " . $e->getMessage());
}

// === (opcjonalnie) TEST 2: sprawdź allowed-countries ======================
// Odkomentuj, jeśli potrzebujesz dodatkowej walidacji reachability.
/*
http_echo("ℹ️ GET /configuration/allowed-countries …");
try {
    $resp2 = furgo_get_json('https://api.furgonetka.pl/configuration/allowed-countries', $accessToken);
    $ok = isset($resp2['all_countries']);
    http_echo($ok ? "✅ OK — słownik krajów dostępny." : "⚠️ Brak pola all_countries.");
} catch (Throwable $e) {
    http_echo("⚠️ FAIL: " . $e->getMessage());
}
*/

http_echo("✔️ Zrobione.");
