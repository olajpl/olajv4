<?php
// 1. opis czynności lub funkcji
// Load/save ustawień globalnych loggera (suadmin)

require_once __DIR__.'/../../../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$is_suadmin = ($_SESSION['user']['role'] ?? '') === 'suadmin';
if (!$is_suadmin) {
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if (($_GET['mode'] ?? '') === 'load') {
    $r = $pdo->query("SELECT enabled_db,enabled_file,min_level,retention_days,file_path FROM log_settings WHERE owner_id IS NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    echo json_encode($r ?: []);
    exit;
}

$enabled_db   = isset($_POST['enabled_db']) ? 1 : 0;
$enabled_file = isset($_POST['enabled_file']) ? 1 : 0;
$min_level    = $_POST['min_level'] ?? 'info';
$retention    = (int)($_POST['retention_days'] ?? 30);
$file_path    = trim($_POST['file_path'] ?? '../var/app.log');

$st = $pdo->prepare("INSERT INTO log_settings (owner_id,enabled_db,enabled_file,min_level,retention_days,file_path)
VALUES (NULL,:db,:file,:lvl,:ret,:path)
ON DUPLICATE KEY UPDATE enabled_db=VALUES(enabled_db), enabled_file=VALUES(enabled_file),
min_level=VALUES(min_level), retention_days=VALUES(retention_days), file_path=VALUES(file_path)");
$ok = $st->execute([':db' => $enabled_db, ':file' => $enabled_file, ':lvl' => $min_level, ':ret' => $retention, ':path' => $file_path]);
echo json_encode(['ok' => $ok]);
