<?php
// /diag/status.php — Olaj.pl V4 szybki healthcheck
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$started = microtime(true);

$report = [
    'ok' => true,
    'ts' => date('c'),
    'checks' => [],
    'errors' => [],
];

$push = function (string $name, $ok, array $extra = []) use (&$report) {
    $report['checks'][] = ['name' => $name, 'ok' => (bool)$ok] + $extra;
    if (!$ok) $report['ok'] = false;
};

try {
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/log.php'; // logg(), wlog()

    // 1) PDO sanity
    $push('pdo_connected', isset($pdo) && $pdo instanceof PDO);
    if (!isset($pdo)) throw new RuntimeException('PDO not booted');

    $attrs = [
        'ERRMODE' => $pdo->getAttribute(PDO::ATTR_ERRMODE),
        'EMULATE_PREPARES' => $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
        'DRIVER' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
    ];
    $push('pdo_config', true, ['data' => $attrs]);

    // 2) Wymagane tabele/kolumny
    $mustTables = [
        'logs' => ['id', 'message', 'level', 'created_at'],
        'owner_settings' => ['id', 'owner_id', 'key', 'value'],
        'clients' => ['id', 'owner_id', 'token'],
        'client_platform_ids' => ['id', 'client_id', 'owner_id', 'platform', 'platform_user_id'],
        'messages' => ['id', 'owner_id', 'direction', 'channel', 'created_at'],
        'fb_webhook_events' => ['id', 'owner_id', 'delivery_id', 'body_sha256'],
        'webhook_requests' => ['id', 'owner_id', 'provider'],
    ];
    $schemaOk = true;
    foreach ($mustTables as $t => $cols) {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $st->execute([':t' => $t]);
        $exists = (bool)$st->fetchColumn();
        if (!$exists) {
            $schemaOk = false;
            $report['errors'][] = "missing_table:$t";
        } else {
            foreach ($cols as $c) {
                $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
                $st->execute([':t' => $t, ':c' => $c]);
                if (!$st->fetchColumn()) {
                    $schemaOk = false;
                    $report['errors'][] = "missing_column:$t.$c";
                }
            }
        }
    }
    $push('schema_required_tables', $schemaOk);

    // 3) Smoke test loggera
    $reqId = function_exists('uuidv4') ? uuidv4() : (function () {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        $h = bin2hex($d);
        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
    })();

    $logMeta = ['owner_id' => 1, 'context' => 'diag', 'source' => 'panel', 'request_id' => $reqId];
    logg('info', 'diag', 'logger_smoke_test', ['ts' => date('c')], $logMeta);

    // sprawdź czy wpis wpadł (jeśli masz SELECT prawa)
    $st = $pdo->prepare("SELECT id, level, channel, event, message, created_at FROM logs WHERE request_id=:r ORDER BY id DESC LIMIT 1");
    $st->execute([':r' => $reqId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $push('logger_persisted', (bool)$row, ['row' => $row ?: null]);
} catch (Throwable $e) {
    $push('logger_exception', false, ['error' => $e->getMessage()]);
}

// 4) owner_settings: klucze FB (opcjonalnie)
$fbKeysOk = true;
$fbKeys = ['facebook.verify_token', 'facebook.app_secret', 'facebook.page_token'];
$got = [];
foreach ($fbKeys as $k) {
    $st = $pdo->prepare("SELECT value FROM owner_settings WHERE owner_id=1 AND `key`=:k LIMIT 1");
    $st->execute([':k' => $k]);
    $v = $st->fetchColumn();
    $got[$k] = $v ? 'set' : 'missing';
    if (!$v) $fbKeysOk = false;
}
$push('owner_settings_facebook', $fbKeysOk, ['keys' => $got]);

// 5) Webhook żyje? — czy plik istnieje i resolver działa
$we = [
    'api/webhook_fb.php' => is_file(__DIR__ . '/../api/webhook_fb.php'),
    'engine/Webhook/WebhookEngine.php' => is_file(__DIR__ . '/../engine/Webhook/WebhookEngine.php'),
];
$push('webhook_files_present', $we['api/webhook_fb.php'] && $we['engine/Webhook/WebhookEngine.php'], ['files' => $we]);

// 6) CW/Messages smoke: czy tabela działa (INSERT -> SELECT) — tylko test „na sucho”
try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO messages(owner_id, direction, channel, content) VALUES (1,'out','manual','diag ping')")->execute();
    $mid = (int)$pdo->lastInsertId();
    $st = $pdo->prepare("SELECT id, direction, channel, content FROM messages WHERE id=:id");
    $st->execute([':id' => $mid]);
    $mrow = $st->fetch(PDO::FETCH_ASSOC);
    $push('messages_insert_select', (bool)$mrow, ['row' => $mrow ?: null]);
    $pdo->rollBack();
} catch (Throwable $e) {
    $push('messages_insert_select', false, ['error' => $e->getMessage()]);
    if ($pdo->inTransaction()) $pdo->rollBack();
} catch (Throwable $e) {
    $report['ok'] = false;
    $report['errors'][] = $e->getMessage();
}

$report['duration_ms'] = (int)round((microtime(true) - $started) * 1000);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
