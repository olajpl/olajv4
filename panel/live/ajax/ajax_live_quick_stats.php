<?php
// admin/live/ajax/ajax_live_quick_stats.php
declare(strict_types=1);

require_once __DIR__ . '/__live_boot.php';

try {
    $owner_id = (int)($_GET['owner_id'] ?? ($_SESSION['user']['owner_id'] ?? 0));
    $live_id  = (int)($_GET['live_id']  ?? 0);

    if ($live_id <= 0) {
        json_out(['items' => 0, 'reservations' => 0]);
    }

    // --- ITEMS: tylko bieżące (nieprzeniesione)
    $sqlItems = "SELECT COUNT(*) 
                   FROM live_temp 
                  WHERE live_id = :lid 
                    AND transferred_at IS NULL";
    $paramsItems = [':lid' => $live_id];

    if ($owner_id > 0) {
        $sqlItems .= " AND owner_id = :oid";
        $paramsItems[':oid'] = $owner_id;
    }

    $st = $pdo->prepare($sqlItems);
    $st->execute($paramsItems);
    $items = (int)$st->fetchColumn();

    // --- RESERVATIONS: aktywne rezerwacje dla tej sesji LIVE
    // Uwaga: kolumna to source_type, nie "source"
    $sqlRes = "SELECT COUNT(*)
                 FROM stock_reservations
                WHERE source_type = 'live'
                  AND status = 'reserved'
                  AND live_id = :lid";
    $paramsRes = [':lid' => $live_id];

    if ($owner_id > 0) {
        $sqlRes .= " AND owner_id = :oid";
        $paramsRes[':oid'] = $owner_id;
    }

    $st = $pdo->prepare($sqlRes);
    $st->execute($paramsRes);
    $res = (int)$st->fetchColumn();

    json_out(['items' => $items, 'reservations' => $res]);
} catch (\Throwable $e) {
    if (function_exists('logg')) {
        logg('error', 'live.quick_stats', 'exception', [
            'owner_id' => $owner_id ?? null,
            'live_id'  => $live_id  ?? null,
            'message'  => $e->getMessage(),
        ]);
    }
    json_out(['items' => 0, 'reservations' => 0]);
}
