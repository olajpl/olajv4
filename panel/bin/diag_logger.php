#!/usr/bin/env php
<?php
declare(strict_types=1);

// uruchamiaj z katalogu projektu: php -d display_errors=1 bin/diag_logger.php
chdir(__DIR__ . '/..');

// 1) DB + LOG
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/log.php';

// 2) Upewnij się, że PDO pluje wyjątkami
if (isset($pdo) && $pdo instanceof PDO) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// 3) Twardy ping do loggera
logg('debug','diag','Logger smoke test', ['ts'=>date('c')], [
    'owner_id'=>1,
    'context'=>'diag',
    'source'=>'cli'
]);

echo "logg() wywołane ✅\n";

// 4) Sprawdź DB (jeśli się da)
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $c = $pdo->query("SELECT COUNT(*) FROM logs")->fetchColumn();
        echo "logs.count = {$c}\n";
    } else {
        echo "Brak PDO – test DB pominięty.\n";
    }
} catch (Throwable $e) {
    echo "SELECT COUNT(*) FROM logs → ERROR: {$e->getMessage()}\n";
    // nadal OK — fallback do pliku powinien coś zapisać
}

// 5) Pokaż ścieżkę logu plikowego, żeby łatwo sprawdzić
$ref = new ReflectionFunction('logg');
$logFile = dirname($ref->getFileName()) . '/../var/app.log';
echo "Sprawdź plik: {$logFile}\n";
