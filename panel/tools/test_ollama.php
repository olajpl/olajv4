<?php
// tools/test_ollama.php — diagnostyka Ollamy + AiEngine (Olaj V4)
declare(strict_types=1);

// 1) Bootstrap (upewnij się, że ścieżka jest OK!)
require_once __DIR__ . '/../../bootstrap.php';

use Engine\Ai\AiEngine;

header('Content-Type: text/plain; charset=utf-8');

// 2) Twardsze logowanie błędów w DEV
if (isset($IS_DEV) && $IS_DEV) {
    ini_set('display_errors', '1'); error_reporting(E_ALL);
}

// 3) Raport startowy
echo "=== Ollama / AiEngine self-test ===\n";
echo "APP_ROOT: " . (defined('APP_ROOT') ? APP_ROOT : '(undef)') . "\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "curl ext: " . (extension_loaded('curl') ? 'YES' : 'NO') . "\n";
echo "OLLAMA_HOST: " . (defined('OLLAMA_HOST') ? OLLAMA_HOST : getenv('OLLAMA_HOST')) . "\n";
echo "AI_DEFAULT_MODEL: " . (defined('AI_DEFAULT_MODEL') ? AI_DEFAULT_MODEL : '(not defined)') . "\n";

// 4) Sanity checks
try {
    if (!extension_loaded('curl')) {
        throw new RuntimeException("PHP cURL extension not loaded.");
    }
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException("PDO not available in bootstrap.");
    }
    // PSR-4 / nazwa pliku vs namespace: Engine\Ai\AiEngine => engine/Ai/AiEngine.php (ważne na Linux!)
    if (!class_exists(\Engine\Ai\AiEngine::class)) {
        throw new RuntimeException("Class Engine\\Ai\\AiEngine not found. Sprawdź ścieżkę pliku: engine/Ai/AiEngine.php (z dużym 'Ai').");
    }

    echo "Bootstrap/Autoload OK.\n";

    // 5) Healthcheck Ollamy
    $ownerId = (int)($_SESSION['user']['owner_id'] ?? 1);
    $ok = AiEngine::healthCheck($pdo, $ownerId);
    echo "healthCheck: " . ($ok ? "OK" : "FAIL") . "\n";

    if (!$ok) {
        echo "Tip: uruchom lokalnie:\n";
        echo "  ollama pull llama3\n";
        echo "  ollama run llama3\n";
        echo "i sprawdź GET /api/version na " . (defined('OLLAMA_HOST') ? OLLAMA_HOST : '(ENV)') . "/api/version\n";
        exit;
    }

    // 6) Zrób prosty prompt (cache-first)
    $res = AiEngine::askCached(
        $pdo,
        $ownerId,
        "Podaj 2 krótkie claimy sprzedażowe dla WC Meister Lavenda (maks 160 znaków).",
        defined('AI_DEFAULT_MODEL') ? AI_DEFAULT_MODEL : 'llama3:latest',
        [
            'system' => env('AI_SYSTEM_PROMPT', 'Jesteś pomocnym asystentem Olaj.pl, odpowiadasz krótko po polsku.'),
            // 'temperature' => 0.7,
        ]
    );

    echo "askCached.hit: " . ($res['hit'] ? 'HIT' : 'MISS') . "\n";
    echo "askCached.hash: " . ($res['hash'] ?? '-') . "\n";
    echo "---- OUTPUT ----\n";
    echo trim((string)($res['text'] ?? '[brak odpowiedzi]')) . "\n";
    echo "----------------\n";

} catch (Throwable $e) {
    // Łapiemy WSZYSTKO i drukujemy czarno na białym
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo "FILE: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "TRACE:\n" . $e->getTraceAsString() . "\n";

    // Log do centralnego loggera, jeśli jest
    if (function_exists('logg')) {
        logg('error', 'tools.test_ollama', 'exception', [
            'msg' => $e->getMessage(),
            'file'=> $e->getFile() . ':' . $e->getLine(),
        ]);
    }
    http_response_code(500);
    exit;
}
