<?php

declare(strict_types=1);

/**
 * /admin/api/notifications_counts.php — Olaj V4
 * Zwraca lekkie KPI do topbara:
 * - orders_new
 * - orders_open_package
 * - payments_in_progress
 * - cw_queue
 * - cw_errors
 * - logs_errors_48h
 *
 * Parametry:
 * - ?debug=1 — dorzuca diagnostykę zapytań
 */

$BASE = dirname(__DIR__, 2);
require_once $BASE . '/includes/auth.php';
require_once $BASE . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$DEBUG = isset($_GET['debug']) && ($_GET['debug'] === '1' || $_GET['debug'] === 'true');

try {
    $owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
    if ($owner_id <= 0) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $meta = []; // tylko w trybie debug

    $safeCount = function (string $sql, array $params = []) use ($pdo, $DEBUG, &$meta): array {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $val = (int)$st->fetchColumn();
            if ($DEBUG) $meta[] = ['ok' => true, 'sql' => $sql, 'params' => $params, 'count' => $val];
            return ['ok' => true, 'count' => $val];
        } catch (Throwable $e) {
            if ($DEBUG) $meta[] = ['ok' => false, 'sql' => $sql, 'params' => $params, 'err' => $e->getMessage()];
            return ['ok' => false, 'count' => null, 'error' => $e->getMessage()];
        }
    };

    $metrics = [];

    // 1) nowe zamówienia
    $r = $safeCount(
        "SELECT COUNT(*) FROM orders WHERE owner_id=:oid AND order_status='nowe'",
        ['oid' => $owner_id]
    );
    $metrics['orders_new'] = $r['count'];

    // 2) otwarte paczki (prefiks otwarta_paczka)
    $r = $safeCount(
        "SELECT COUNT(*) FROM orders WHERE owner_id=:oid AND (order_status='otwarta_paczka' OR order_status LIKE 'otwarta_paczka:%')",
        ['oid' => $owner_id]
    );
    $metrics['orders_open_package'] = $r['count'];

    // 3) payments in progress
    $r = $safeCount(
        "SELECT COUNT(*) FROM payments WHERE owner_id=:oid AND status IN ('started','pending')",
        ['oid' => $owner_id]
    );
    $metrics['payments_in_progress'] = $r['count'];

    // 4) CW queue
    $r = $safeCount(
        "SELECT COUNT(*) FROM messages WHERE owner_id=:oid AND direction='out' AND status='queued'",
        ['oid' => $owner_id]
    );
    $metrics['cw_queue'] = $r['count'];

    // 5) CW errors
    $r = $safeCount(
        "SELECT COUNT(*) FROM messages WHERE owner_id=:oid AND direction='out' AND status='error'",
        ['oid' => $owner_id]
    );
    $metrics['cw_errors'] = $r['count'];

    // 6) Logs errors 48h (jeśli brak tabeli/kolumn — poleci null w debug)
    $r = $safeCount(
        "SELECT COUNT(*) FROM logs
         WHERE owner_id=:oid
           AND level IN ('error','critical','alert','emergency')
           AND created_at >= (NOW() - INTERVAL 48 HOUR)",
        ['oid' => $owner_id]
    );
    $metrics['logs_errors_48h'] = $r['count'];

    $out = [
        'ok'          => true,
        'owner_id'    => $owner_id,
        'server_time' => date('c'),
        'metrics'     => $metrics,
    ];
    if ($DEBUG) $out['debug'] = $meta;

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    $payload = ['error' => 'internal', 'message' => 'Failed to load KPI'];
    if ($DEBUG) {
        $payload['exception'] = $e->getMessage();
        $payload['trace']     = $e->getTraceAsString();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}
