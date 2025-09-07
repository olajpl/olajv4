<?php
// admin/live/ajax/ajax_check_availability.php — Olaj.pl V4 (final, bez Engine\*)
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 3));
require_once APP_ROOT . '/bootstrap.php';

use PDO;

// ── DIAGNOSTYKA NATYCHMIAST ───────────────────────────────────────────────
// 1) ping: szybkie sprawdzenie czy trafiamy W TEN plik
if (isset($_GET['ping'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Endpoint-File: ' . __FILE__);
    echo json_encode([
        'ok'    => true,
        'file'  => __FILE__,
        'mtime' => @filemtime(__FILE__),
        'time'  => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
// 2) opcjonalny flush OPcache (1 żądanie): /ajax_check_availability.php?flush=1
if (!empty($_GET['flush']) && function_exists('opcache_reset')) { @opcache_reset(); }

// ── WŁAŚCIWY ENDPOINT ─────────────────────────────────────────────────────
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');

function out(array $p, int $code = 200): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    /** @var PDO|null $pdo */
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) throw new RuntimeException('PDO not available');

    $ownerId   = (int)($_GET['owner_id']   ?? ($_SESSION['user']['owner_id'] ?? 0));
    $productId = (int)($_GET['product_id'] ?? 0);
    $wantQty   = isset($_GET['qty']) ? (float)$_GET['qty'] : null;
    $liveId    = isset($_GET['live_id']) ? (int)$_GET['live_id'] : null;
    $whId      = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;

    if ($ownerId <= 0 || $productId <= 0) {
        out(['ok'=>false,'error'=>'Bad params'], 400);
    }

    // 1) on_hand ze stock_movements
    $sqlOnHand = "
        SELECT COALESCE(SUM(
            CASE
              WHEN movement_type IN ('in','return') OR movement_type_key IN ('in','return') THEN qty
              WHEN movement_type='out' OR movement_type_key='out' THEN -qty
              WHEN movement_type='adjust' OR movement_type_key='adjust' THEN qty
              ELSE 0
            END
        ),0) AS on_hand
        FROM stock_movements
        WHERE owner_id = :owner AND product_id = :pid
        " . ($whId ? " AND warehouse_id = :wh " : "") . "
    ";
    $st = $pdo->prepare($sqlOnHand);
    $st->bindValue(':owner', $ownerId, PDO::PARAM_INT);
    $st->bindValue(':pid',   $productId, PDO::PARAM_INT);
    if ($whId) $st->bindValue(':wh', $whId, PDO::PARAM_INT);
    $st->execute();
    $onHand = (float)($st->fetchColumn() ?: 0.0);

    // 2) reserved ze stock_reservations (reserved|committed)
    $sqlRes = "
        SELECT COALESCE(SUM(qty),0) AS reserved
        FROM stock_reservations
        WHERE owner_id = :owner AND product_id = :pid
          AND (status IN ('reserved','committed') OR status_key IN ('reserved','committed'))
          " . ($liveId ? " AND (live_id = :live OR :live IS NULL) " : "") . "
    ";
    $st2 = $pdo->prepare($sqlRes);
    $st2->bindValue(':owner', $ownerId, PDO::PARAM_INT);
    $st2->bindValue(':pid',   $productId, PDO::PARAM_INT);
    if ($liveId) $st2->bindValue(':live', $liveId, PDO::PARAM_INT);
    $st2->execute();
    $reserved = (float)($st2->fetchColumn() ?: 0.0);

    $available = max(0.0, $onHand - $reserved);

    $status = 'ok';
    $msg    = 'Dostępne';
    if ($available <= 0.0)                { $status='none';    $msg='Brak na stanie'; }
    elseif ($wantQty !== null && $available < $wantQty) { $status='partial'; $msg="Dostępne tylko {$available}"; }

    out([
        'ok'        => true,
        'status'    => $status,
        'on_hand'   => $onHand,
        'reserved'  => $reserved,
        'available' => $available,
        'want'      => $wantQty,
        'message'   => $msg,
        // pomoc debug
        '_file'     => __FILE__,
        '_mtime'    => @filemtime(__FILE__),
    ]);
} catch (Throwable $e) {
    out(['ok'=>false,'error'=>'exception','message'=>$e->getMessage(),'_file'=>__FILE__], 500);
}
