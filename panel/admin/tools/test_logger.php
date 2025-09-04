<?php
declare(strict_types=1);
session_start();
$_SESSION['user']['owner_id'] = $_SESSION['user']['owner_id'] ?? 1;

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';

header('Content-Type: text/plain; charset=utf-8');

$owner = (int)($_SESSION['user']['owner_id'] ?? 0);

// wykryj ścieżkę, którą używa LogEngine (mamy helper? – jeśli nie, wpisz ręcznie /var/app.log)
$root = realpath(__DIR__ . '/../../');
$logFile = $root . '/var/app.log';

echo "== TEST LOGGER ==\n";
echo "👤 owner_id: $owner\n";
echo "📄 log_file: $logFile\n";

// 1) spróbuj stworzyć plik w var/ (powinno się udać)
$probe = $root . '/var/logger_probe.log';
$ok = @file_put_contents($probe, date('c')." probe\n", FILE_APPEND);
echo "📄 probe:    $probe\n";
echo "🧪 probe write: " . ($ok !== false ? "OK\n" : "FAIL\n");

// 2) sanity PDO
echo "🔌 PDO: " . (($pdo ?? null) instanceof PDO ? "OK\n" : "NULL\n");

// 3) wyślij wpisy
logg('info', 'test', '🧪 wpis INFO', ['foo'=>'bar'], ['context'=>'logger_test']);
logg('warning', 'test', '⚠️ wpis WARNING', ['issue'=>'config_missing'], ['context'=>'logger_test']);
logg('error', 'test', '❌ wpis ERROR', ['debug_id'=>uniqid('dbg_')], ['context'=>'logger_test']);

try { throw new RuntimeException('Wymuszony wyjątek'); }
catch (Throwable $e) { log_exception($e, 'test', '💥 exception test', ['context'=>'logger_test']); }

echo "📥 Logi zostały wysłane do loggera.\n\n";

// 4) pokaż końcówkę pliku (bez shell_exec)
if (is_file($logFile)) {
    $tail = @file_get_contents($logFile);
    $tail = $tail !== false ? $tail : '';
    $last = implode("", array_slice(explode("\n", $tail), -10));
    echo "== TAIL app.log ==\n" . $last . "\n";
} else {
    echo "⚠️ Brak pliku app.log (sprawdź open_basedir/perm).\n";
}

echo "\n== KONIEC TESTU LOGGERA ==\n";
