<?php
// admin/live/ajax/ajax_delete_live_product.php
declare(strict_types=1);

require_once __DIR__ . '/__live_boot.php';

use Engine\Stock\StockReservationEngine;

try {
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

    // Kontekst (preferuję helper ctx(), ale jeśli go nie masz – pobieraj z sesji/POST)
    $owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
    $live_id  = (int)($_POST['live_id'] ?? 0);
    $id       = (int)($_POST['id'] ?? 0);

    if ($owner_id <= 0 || $live_id <= 0 || $id <= 0) {
        json_out(['success' => false, 'error' => 'Brak kontekstu (owner/live/id).'], 422);
    }

    // Pobierz rekord – tylko nieprzeniesione pozycje
    $st = $pdo->prepare("
        SELECT id, owner_id, live_id, product_id, qty, source_type, reservation_id, transferred_at
          FROM live_temp
         WHERE id = :id AND owner_id = :oid AND live_id = :lid
         LIMIT 1
    ");
    $st->execute([':id' => $id, ':oid' => $owner_id, ':lid' => $live_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        json_out(['success' => false, 'error' => 'Nie znaleziono pozycji.'], 404);
    }
    if (!empty($row['transferred_at'])) {
        json_out(['success' => false, 'error' => 'Pozycja została już przeniesiona i nie może być usunięta.'], 409);
    }

    $isCatalog = ($row['source_type'] ?? '') === 'catalog';
    $productId = (int)($row['product_id'] ?? 0);
    $qty       = (float)($row['qty'] ?? 0);
    $resId     = (int)($row['reservation_id'] ?? 0);

    $pdo->beginTransaction();

    try {
        // 1) Zwolnij rezerwację (jeśli katalogowa)
        if ($isCatalog && $productId > 0 && $qty > 0) {
            $released = false;

            // Preferencja: centralny engine rezerwacji
            if (class_exists(StockReservationEngine::class) && $resId > 0) {
                // release() powinien sam zaktualizować agregaty, jeśli Twój engine to obsługuje
                StockReservationEngine::release($pdo, $resId);
                $released = true;
            }

            // Fallback — gdy engine nie robi agregatu:
            if (!$released) {
                // status rezerwacji (jeśli istnieje) → released
                if ($resId > 0) {
                    $pdo->prepare("
                        UPDATE stock_reservations
                           SET status = 'released', released_at = NOW()
                         WHERE id = :rid AND owner_id = :oid
                           AND status = 'reserved'
                    ")->execute([':rid' => $resId, ':oid' => $owner_id]);
                }

                // Zmniejsz agregat rezerwacji w products (nie schodź poniżej zera)
                $pdo->prepare("
                    UPDATE products
                       SET stock_reserved_cached = GREATEST(0, stock_reserved_cached - :q),
                           updated_at = NOW()
                     WHERE id = :pid AND owner_id = :oid
                ")->execute([':q' => $qty, ':pid' => $productId, ':oid' => $owner_id]);
            }
        }

        // 2) Usuń wiersz z live_temp (scope: owner+live)
        $pdo->prepare("
            DELETE FROM live_temp 
             WHERE id = :id AND owner_id = :oid AND live_id = :lid
            LIMIT 1
        ")->execute([':id' => $id, ':oid' => $owner_id, ':lid' => $live_id]);

        $pdo->commit();

        if (function_exists('logg')) {
            logg('info', 'live.delete', 'removed', [
                'owner_id'   => $owner_id,
                'live_id'    => $live_id,
                'row_id'     => $id,
                'product_id' => $productId,
                'qty'        => $qty,
                'catalog'    => $isCatalog,
                'res_id'     => $resId
            ]);
        }

        json_out(['success' => true]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (function_exists('logg')) {
            logg('error', 'live.delete', 'exception', [
                'owner_id' => $owner_id,
                'live_id'  => $live_id,
                'row_id'   => $id,
                'error'    => $e->getMessage()
            ]);
        }
        json_out(['success' => false, 'error' => $e->getMessage()], 500);
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['success' => false, 'error' => $e->getMessage()], 500);
}
