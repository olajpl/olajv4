<?php
// /api/diag/health.php — Olaj V4
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$resp = ['ok' => false, 'checks' => []];

try {
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/log.php';

    // 1) DB ping
    try {
        $pdo->query('SELECT 1');
        $resp['checks']['db'] = 'ok';
    } catch (Throwable $e) {
        $resp['checks']['db'] = 'fail: ' . $e->getMessage();
    }

    // 2) Wymagane tabele (minimal set)
    $required = [
        'messages',
        'clients',
        'client_platform_ids',
        'owner_settings',
        'fb_webhook_events' // tworzone on-the-fly przez WebhookEngine, ale sprawdzamy
    ];
    foreach ($required as $t) {
        try {
            $pdo->query("DESCRIBE `$t`");
            $resp['checks']["table:$t"] = 'ok';
        } catch (Throwable $e) {
            $resp['checks']["table:$t"] = 'missing';
        }
    }

    // 3) Logger smoke
    try {
        if (function_exists('logg')) {
            logg('info', 'diag.health', 'health ping', ['ts' => date('c')], [
                'context' => 'diag',
                'source' => 'health',
                'owner_id' => 1
            ]);
            $resp['checks']['logger'] = 'ok';
        } else {
            $resp['checks']['logger'] = 'missing logg()';
        }
    } catch (Throwable $e) {
        $resp['checks']['logger'] = 'fail: ' . $e->getMessage();
    }

    // 4) Prawa zapisu do /tmp
    $tmp = sys_get_temp_dir();
    $fn = $tmp . '/olaj_health_' . bin2hex(random_bytes(3)) . '.txt';
    $ok = @file_put_contents($fn, 'ok ' . date('c'));
    $resp['checks']['tmp_write'] = $ok ? "ok:$tmp" : 'fail';
    if ($ok) @unlink($fn);

    // 5) Parser obecny?
    $paths = [
        __DIR__ . '/../engine/Parser/ParserEngine.php',
        __DIR__ . '/../includes/parser.php'
    ];
    $found = array_values(array_filter($paths, 'is_file'));
    $resp['checks']['parser'] = $found ? ('found: ' . basename($found[0])) : 'missing';

    $resp['ok'] = true;
} catch (Throwable $e) {
    $resp['ok'] = false;
    $resp['error'] = $e->getMessage();
}
echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
