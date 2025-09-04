<?php
define('APP_OWNER_ID', 1);

$host = 'borowikmar1.mysql.dhosting.pl';
$dbname = 'eew3ha_adminola';
$user = 'niew4m_adminola';
$pass = 'b0anUfr+th9ti';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $db = new PDO($dsn, $user, $pass, $options);
    $pdo = $db; // ← alias dla nowego kodu
} catch (PDOException $e) {
    exit('Błąd połączenia z bazą danych: ' . $e->getMessage());
}
