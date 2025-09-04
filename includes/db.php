<?php
// includes/db.php
// Inicjalizacja PDO z .env z bezpiecznymi wartościami.
// Udostępnia $pdo oraz alias $db. Zwraca $pdo (dla legacy require_once).

declare(strict_types=1);

// 1. Loader ENV (wymaga includes/env.php)
require_once __DIR__ . '/env.php';

// 2. Helper fallback (jeśli env() nie jest załadowane z includes/env.php)
if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $val = getenv($key);
        return ($val === false || $val === null || $val === '') ? $default : $val;
    }
}

// 3. Dane z ENV (nic na sztywno!)
$host    = env('DB_HOST');
$dbname  = env('DB_NAME');
$user    = env('DB_USER');
$pass    = env('DB_PASS');
$charset = env('DB_CHARSET', 'utf8mb4');

// 4. Walidacja
if (!$host || !$dbname || !$user || $pass === null) {
    http_response_code(500);
    exit('🛑 Konfiguracja DB niekompletna. Uzupełnij .env (DB_HOST, DB_NAME, DB_USER, DB_PASS).');
}

// 5. Flagi i sekrety globalne
define('LIVE_INGEST_HMAC_SECRET', env('LIVE_INGEST_HMAC_SECRET', 'CHANGE_ME_INGEST_SECRET'));
define('PAYMENTS_V2_ENABLED', filter_var(env('PAYMENTS_V2_ENABLED', '0'), FILTER_VALIDATE_BOOL));

// 6. DSN + bezpieczne opcje
$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    // PDO::ATTR_PERSISTENT      => true, // opcjonalnie
];

// 7. Nawiązanie połączenia + alias
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $db  = $pdo;

    // $pdo->exec("SET time_zone = '+00:00'"); // jeśli masz ENV('DB_TIMEZONE')
} catch (PDOException $e) {
    http_response_code(500);
    exit('❌ Błąd połączenia z bazą danych.');
}

// 8. Zwracamy $pdo dla require_once
return $pdo;
