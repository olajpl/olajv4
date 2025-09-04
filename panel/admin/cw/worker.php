<?php
// admin/cw/worker.php — Worker CW (manual + cron-safe)
// - Przetwarza wiadomości z messages.status='queued'
// - Uwzględnia next_attempt_at (opóźnienia / retry)
// - Wywołuje Cw::trySend()

declare(strict_types=1);

use CentralMessaging\Cw;
use Engine\Log\LogEngine;

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/engine/centralMessaging/Cw.php';
require_once dirname(__DIR__, 2) . '/engine/Log/LogEngine.php';

$start = microtime(true);
$limit = 100;
$processed = 0;
$ok = 0;
$fail = 0;

try {
    $st = $pdo->prepare("
        SELECT id
        FROM messages
        WHERE status = 'queued'
          AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
        ORDER BY id ASC
        LIMIT ?
    ");
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    $processed = count($ids);

    $log = LogEngine::create($pdo, 1); // owner_id = 1 jako fallback

    foreach ($ids as $id) {
        try {
            if (Cw::trySend($pdo, (int)$id)) {
                $ok++;
            } else {
                $fail++;
                $log->warn('cw.worker', 'Wysyłka nieudana', ['message_id' => (int)$id]);
            }
        } catch (Throwable $e) {
            $fail++;
            $log->error('cw.worker', 'Błąd podczas próby wysyłki', [
                'message_id' => (int)$id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    $log->info('cw.worker', 'Zakończono przetwarzanie CW worker', [
        'count' => $processed,
        'success' => $ok,
        'failed' => $fail,
        'duration_ms' => round((microtime(true) - $start) * 1000)
    ]);

    echo json_encode([
        'ok' => true,
        'processed' => $processed,
        'success' => $ok,
        'failed' => $fail,
        'duration_ms' => round((microtime(true) - $start) * 1000)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
