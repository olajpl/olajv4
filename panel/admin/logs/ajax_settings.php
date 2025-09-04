<?php
// admin/logs/ajax_settings.php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

// suadmin guard
$is_suadmin = false;
if (function_exists('is_suadmin')) {
    $is_suadmin = is_suadmin();
} else {
    $role = strtolower($_SESSION['user']['role'] ?? '');
    $is_suadmin = in_array($role, ['superadmin', 'suadmin'], true);
}
if (!$is_suadmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$mode = $_GET['mode'] ?? '';
if ($mode === 'load') {
    // wczytaj z global_settings
    $keys = [
        'logging.enabled_db'   => '0',
        'logging.enabled_file' => '1',
        'logging.min_level'    => 'info',
        'logging.retention_days' => '30',
        'logging.file_path'    => '../var/app.log',
    ];
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = $pdo->prepare("SELECT `key`,`value` FROM global_settings WHERE `key` IN ($in)");
    $st->execute(array_keys($keys));
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $keys[$r['key']] = $r['value'];
    }
    echo json_encode([
        'ok' => true,
        'enabled_db'   => (int)$keys['logging.enabled_db'],
        'enabled_file' => (int)$keys['logging.enabled_file'],
        'min_level'    => (string)$keys['logging.min_level'],
        'retention_days' => (int)$keys['logging.retention_days'],
        'file_path'    => (string)$keys['logging.file_path'],
    ]);
    exit;
}

// save
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$enabled_db    = isset($_POST['enabled_db']) ? 1 : 0;
$enabled_file  = isset($_POST['enabled_file']) ? 1 : 0;
$min_level     = $_POST['min_level'] ?? 'info';
$retention     = (int)($_POST['retention_days'] ?? 30);
$file_path     = trim((string)($_POST['file_path'] ?? '../var/app.log'));

$rows = [
    ['logging.enabled_db', (string)$enabled_db],
    ['logging.enabled_file', (string)$enabled_file],
    ['logging.min_level', $min_level],
    ['logging.retention_days', (string)$retention],
    ['logging.file_path', $file_path],
];

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare("
    INSERT INTO global_settings(`key`,`value`,`type`)
    VALUES(:k,:v,'string')
    ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)
  ");
    foreach ($rows as [$k, $v]) {
        $st->execute([':k' => $k, ':v' => $v]);
    }
    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
