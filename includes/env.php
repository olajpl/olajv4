<?php
// includes/env.php — ładowanie zmiennych z .env (automatyczna lokalizacja)

function findEnvPath(): ?string
{
    $candidates = [
        __DIR__ . '/../panel/.env',
        __DIR__ . '/../.env',
        __DIR__ . '/.env',
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) return $file;
    }
    return null;
}

$envFile = findEnvPath();
if ($envFile) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"' ");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        @putenv("$name=$value");
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null) {
    $val = getenv($key);
    return ($val === false || $val === null) ? $default : $val;
}
}
if (env('DEBUG') === '1') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}