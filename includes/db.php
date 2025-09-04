<?php
declare(strict_types=1);

// ✅ Najważniejsze!
require_once __DIR__ . '/env.php';

// Dane z ENV
$host    = env('DB_HOST');
$dbname  = env('DB_NAME');
$user    = env('DB_USER');
$pass    = env('DB_PASS');
$charset = env('DB_CHARSET', 'utf8mb4');

// Walidacja
if (
    !is_string($host) || trim($host) === '' ||
    !is_string($dbname) || trim($dbname) === '' ||
    !is_string($user) || trim($user) === '' ||
    $pass === null // <-- puste hasło '' jest OK
) {
    http_response_code(500);
    exit('🛑 Konfiguracja DB niekompletna. Uzupełnij .env (DB_HOST, DB_NAME, DB_USER, DB_PASS).');
}


// Reszta (PDO itd.)


// 4. Flagi i sekrety globalne
define('LIVE_INGEST_HMAC_SECRET', env('LIVE_INGEST_HMAC_SECRET', 'CHANGE_ME_INGEST_SECRET'));
define('PAYMENTS_V2_ENABLED', filter_var(env('PAYMENTS_V2_ENABLED', '0'), FILTER_VALIDATE_BOOL));

// 5. DSN + bezpieczne opcje
$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// 6. Nawiązanie połączenia + alias
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $db  = $pdo;
} catch (PDOException $e) {
    http_response_code(500);
    exit('❌ Błąd połączenia z bazą danych.');
}

// 7. Zwracamy $pdo dla require_once
return $pdo;
