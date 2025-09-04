<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/log.php';

try {
    logg('info', 'diag', 'Logger smoke test', ['ts' => date('c')], [
        'owner_id' => 1,
        'context' => 'diag',
        'source' => 'logger_smoke'
    ]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
