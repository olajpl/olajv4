<?php

declare(strict_types=1);

/**
 * /admin/api/notifications.php — Olaj V4 (refactor-safe)
 * - Fallbacki kolumn (created_at/id, direction, registered_at/updated_at/last_seen/id)
 * - 401 JSON gdy brak sesji (owner_id)
 * - ?debug=1|true → meta diagnostyka
 */

$BASE = dirname(__DIR__, 2);
require_once $BASE . '/includes/auth.php';
require_once $BASE . '/includes/db.php';
require_once $BASE . '/includes/log.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$DEBUG = isset($_GET['debug']) && ($_GET['debug'] === '1' || $_GET['debug'] === 'true');

try {
    // --- Auth guard ---
    $owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
    if ($owner_id <= 0) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- PDO guard ---
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('DB handle $pdo is not available');
    }

    // --- Helper: bezpieczne zapytania z fallbackami + meta debug ---
    $meta = [];
    $safeQuery = function (string $sql, array $params = []) use ($pdo, $DEBUG, &$meta) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            if ($DEBUG) $meta[] = ['ok' => true, 'sql' => $sql, 'params' => $params];
            return $st;
        } catch (Throwable $e) {
            if ($DEBUG) $meta[] = ['ok' => false, 'sql' => $sql, 'params' => $params, 'err' => $e->getMessage()];
            return null;
        }
    };

    $notifications = [];

    // === 🆕 Ostatnie zamówienie ===
    $qOrder1 = "
        SELECT o.id, c.name
        FROM orders o
        LEFT JOIN clients c ON o.client_id = c.id
        WHERE o.owner_id = :owner_id
        ORDER BY o.created_at DESC
        LIMIT 1
    ";
    $st = $safeQuery($qOrder1, ['owner_id' => $owner_id]);
    if (!$st) {
        $qOrder2 = str_replace('ORDER BY o.created_at DESC', 'ORDER BY o.id DESC', $qOrder1);
        $st = $safeQuery($qOrder2, ['owner_id' => $owner_id]);
    }
    $order = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    if ($order) {
        $orderId    = (int)($order['id'] ?? 0);
        $clientName = trim((string)($order['name'] ?? ''));
        $notifications[] = '🆕 Nowe zamówienie od ' . ($clientName !== '' ? $clientName : ('ID #' . $orderId));
    }

    // === 💬 Ostatnia wiadomość (preferuj direction='in', ale fallback bez kolumny) ===
    $qMsg1 = "
        SELECT c.name
        FROM messages m
        LEFT JOIN clients c ON m.client_id = c.id
        WHERE m.owner_id = :owner_id
          AND m.direction = 'in'
        ORDER BY m.created_at DESC
        LIMIT 1
    ";
    $st = $safeQuery($qMsg1, ['owner_id' => $owner_id]);
    if (!$st) {
        // bez AND m.direction = 'in'
        $qMsg2 = str_replace("AND m.direction = 'in'", '', $qMsg1);
        $st = $safeQuery($qMsg2, ['owner_id' => $owner_id]);
        if (!$st) {
            // sort po id gdy brak created_at
            $qMsg3 = str_replace('ORDER BY m.created_at DESC', 'ORDER BY m.id DESC', $qMsg2);
            $st = $safeQuery($qMsg3, ['owner_id' => $owner_id]);
        }
    }
    $message = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    if ($message) {
        $clientName = trim((string)($message['name'] ?? ''));
        $notifications[] = '💬 Nowa wiadomość od ' . ($clientName !== '' ? $clientName : 'nieznany klient');
    }

    // === 👤 Ostatni klient (registered_at → updated_at → last_seen → id) ===
    $qClient1 = "
        SELECT name
        FROM clients
        WHERE owner_id = :owner_id
        ORDER BY registered_at DESC
        LIMIT 1
    ";
    $st = $safeQuery($qClient1, ['owner_id' => $owner_id]);
    if (!$st) {
        $qClient2 = str_replace('registered_at', 'updated_at', $qClient1);
        $st = $safeQuery($qClient2, ['owner_id' => $owner_id]);
        if (!$st) {
            $qClient3 = str_replace('updated_at', 'last_seen', $qClient2);
            $st = $safeQuery($qClient3, ['owner_id' => $owner_id]);
            if (!$st) {
                $qClient4 = str_replace('ORDER BY registered_at DESC', 'ORDER BY id DESC', $qClient1);
                $st = $safeQuery($qClient4, ['owner_id' => $owner_id]);
            }
        }
    }
    $client = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    if ($client) {
        $clientName = trim((string)($client['name'] ?? ''));
        if ($clientName !== '') {
            $notifications[] = '👤 Nowy klient: ' . $clientName;
        }
    }

    // --- Output ---
    $format = isset($_GET['format']) ? strtolower((string)$_GET['format']) : 'strings';

    if ($format === 'objects') {
        $typed = [];
        foreach ($notifications as $line) {
            $icon = '🔔';
            $type = 'info';

            if (str_starts_with($line, "🆕")) {
                $icon = '🆕';
                $type = 'order';
            } elseif (str_starts_with($line, "💬")) {
                $icon = '💬';
                $type = 'message';
            } elseif (str_starts_with($line, "👤")) {
                $icon = '👤';
                $type = 'client';
            }

            // Usuń *pierwszy* klaster (emoji) + spacje — bez mbstring
            $text = preg_replace('/^\X\s*/u', '', $line, 1);
            $typed[] = ['icon' => $icon, 'text' => $text, 'type' => $type];
        }
        $payload = ['ok' => true, 'notifications' => $typed];
        if ($DEBUG) $payload['debug'] = $meta;
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } else {
        echo $DEBUG
            ? json_encode(['ok' => true, 'notifications' => $notifications, 'debug' => $meta], JSON_UNESCAPED_UNICODE)
            : json_encode($notifications, JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    try {
        if (function_exists('logg')) {
            logg('error', 'admin.api.notifications', 'Unhandled exception', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);
        }
    } catch (Throwable $__) {
    }
    http_response_code(500);
    echo json_encode(['error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
    exit;
}
