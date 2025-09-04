<?php
// includes/env.php — loader + bezpieczne env()

$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"' ");
        // wpisujemy do superglobali — getenv bywa zablokowane
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        // putenv może być wyłączone — ale nie szkodzi spróbować:
        @putenv("$name=$value");
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        // kolejność: $_ENV → $_SERVER → getenv()
        if (array_key_exists($key, $_ENV)) {
            $v = $_ENV[$key];
        } elseif (array_key_exists($key, $_SERVER)) {
            $v = $_SERVER[$key];
        } else {
            $v = getenv($key);
        }
        return ($v === false || $v === null || $v === '') ? $default : $v;
    }
}
