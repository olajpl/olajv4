<?php
// /admin/shipping/test_logger.php
// Minimalny sanity-check logowania (bez include'ów, bez sesji, bez CSRF)

header('Content-Type: text/plain; charset=utf-8');

echo "TEST LOGGER START\n";

// 1) error_log – zawsze powinno pójść do globalnego error_log PHP
error_log("[TEST_LOGGER] hello from error_log; uri=" . ($_SERVER['REQUEST_URI'] ?? 'cli'));

// 2) zapis do pliku w storage/logs/  (tworzy katalog jeśli brak)
$logDir = __DIR__ . '/../../storage/logs';
@mkdir($logDir, 0775, true);
$file = $logDir . '/furgonetka_test.log';

$line = "[" . date('Y-m-d H:i:s') . "] hello from file logger; ip=" . ($_SERVER['REMOTE_ADDR'] ?? '-') . PHP_EOL;
$ok = @file_put_contents($file, $line, FILE_APPEND);
echo "file_put_contents: " . ($ok !== false ? "OK ($file)" : "FAIL ($file)") . "\n";

// 3) centralny logger jeżeli funkcja logg() byłaby globalnie dostępna (tu nie ładujemy include'ów)
if (function_exists('logg')) {
    try {
        logg('debug', 'diag.test_logger', 'hello from logg()', ['from'=>'test_logger.php'], ['source'=>'shipping_test']);
        echo "logg(): OK\n";
    } catch (Throwable $e) {
        echo "logg(): ERROR: " . $e->getMessage() . "\n";
        error_log("[TEST_LOGGER] logg() error: " . $e->getMessage());
    }
} else {
    echo "logg(): not defined (to OK w tym teście)\n";
}

echo "TEST LOGGER END\n";
