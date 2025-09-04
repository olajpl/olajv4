<?php
declare(strict_types=1);

/**
 * Pobranie etykiety (labels) z Furgonetki dla paczki/etykiety.
 *
 * Wejście (GET/POST):
 *   - ownerId   (int)  – ID właściciela (dla wyboru tokenu)
 *   - packageId (int)  – ID paczki (package_id z createPackage)
 *   - type      (str)  – 'labels' (domyślnie), 'protocols' itp.
 *
 * Działanie:
 *   - wywołuje FurgonetkaAPI::getDocumentsUrl()
 *   - proxy PDF bezpośrednio do przeglądarki (Content-Type: application/pdf)
 *
 * Uwaga:
 *   - wymaga PDO z lokalnego bootstrapa
 *   - nie zapisuje na dysku, proxy bezpośrednio (brak open_basedir problemu)
 */

// ===== Bootstrap =====
$ROOT = dirname(__DIR__, 2);
$LOCAL_BOOTSTRAP = $ROOT . '/_bootstrap.php';
if (!is_file($LOCAL_BOOTSTRAP)) {
    http_response_code(500);
    echo "Brak pliku bootstrap: {$LOCAL_BOOTSTRAP}";
    exit;
}
require_once $LOCAL_BOOTSTRAP;

// ===== Mini helpery =====
function must_int(mixed $v, string $name): int {
    if ($v === null || $v === '' || !is_numeric($v)) {
        http_response_code(400);
        echo "Missing/invalid {$name}";
        exit;
    }
    return (int)$v;
}

try {
    $ownerId   = must_int($_REQUEST['ownerId'] ?? null, 'ownerId');
    $packageId = must_int($_REQUEST['packageId'] ?? null, 'packageId');
    $type      = $_REQUEST['type'] ?? 'labels';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        http_response_code(500);
        echo "Brak połączenia PDO (upewnij się, że bootstrap został załadowany).";
        exit;
    }

    // ===== Import API =====
    $API_FILE = dirname(__DIR__, 2) . '/engine/shipping/FurgonetkaAPI.php';
    if (!is_file($API_FILE)) {
        http_response_code(500);
        echo "Brak pliku: {$API_FILE}";
        exit;
    }
    require_once $API_FILE;

    $logger = function(string $tag, array $ctx = []) {
        error_log('[furgo.label] ' . $tag . ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE));
    };

    $api = new FurgonetkaAPI($pdo, ownerId: $ownerId, sandbox: false, logger: $logger);

    // pobierz URL dokumentów
    $url = $api->getDocumentsUrl([$packageId], [$type], [
        'format' => 'A6', // 'A4' / 'A6'
        'type'   => 'pdf',
    ]);

    if (!$url) {
        throw new RuntimeException("Brak URL etykiety dla paczki {$packageId}");
    }

    // proxy dokument
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $pdf = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300 || !$pdf) {
        throw new RuntimeException("Błąd pobierania etykiety z {$url} (HTTP {$code})");
    }

    if (!headers_sent()) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="label-'.$packageId.'.pdf"');
    }
    echo $pdf;
    exit;

} catch (Throwable $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
