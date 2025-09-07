<?php
// admin/live/ajax/ajax_finalize_batch.php
declare(strict_types=1);

require_once __DIR__ . '/__live_boot.php';

use Engine\Live\LiveEngine;

if (!function_exists('json_out')) {
    function json_out(array $payload, int $status = 200): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

try {
    // Kontekst z sesji / POST
    $owner_id   = (int)($_SESSION['user']['owner_id'] ?? 0);
    $operatorId = (int)($_SESSION['user']['id'] ?? 0);
    $live_id    = (int)($_POST['live_id'] ?? 0);

    if ($owner_id <= 0 || $live_id <= 0) {
        json_out(['success' => false, 'error' => 'Brak kontekstu (owner_id/live_id).'], 422);
    }

    // Boot silnika LIVE (autoloader ładuje Engine\Live\LiveEngine)
    $live = LiveEngine::boot($pdo, $owner_id);

    // Finalizacja: przeniesienie pozycji do order_items + commit rezerwacji
    $migrated = $live->finalizeBatch($live_id, $operatorId); // int: ile przeniesiono

    json_out([
        'success'  => true,
        'migrated' => (int)$migrated
    ]);
} catch (\Throwable $e) {
    if (function_exists('logg')) {
        logg('error', 'live.finalize', 'exception', [
            'owner_id'   => $owner_id ?? null,
            'live_id'    => $live_id ?? null,
            'operatorId' => $operatorId ?? null,
            'message'    => $e->getMessage(),
        ]);
    }
    json_out(['success' => false, 'error' => $e->getMessage()], 500);
}
