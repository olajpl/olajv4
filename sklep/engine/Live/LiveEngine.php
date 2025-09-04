<?php

declare(strict_types=1);

namespace Engine\Live;

use PDO;
use Throwable;
use RuntimeException;
use Engine\Enum\OrderItemSource;
use Engine\Orders\OrderEngine;
use Engine\Stock\StockReservationEngine;

final class LiveEngine
{
    // …─── DOTYCHCZASOWE: addProduct, finalizeBatch ───…

    public static function getLiveStream(PDO $pdo, int $liveId, int $ownerId): ?array
    {
        $sql = "SELECT * FROM live_streams WHERE id = :id AND owner_id = :owner_id LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $liveId, 'owner_id' => $ownerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ADD (rozbudowana)
    public static function addComment(PDO $pdo, array $row): int
    {
        $sql = "INSERT INTO live_comments (
                owner_id, live_stream_id, order_id, client_id,
                source, external_comment_id, parent_external_id,
                message, attachments_json, is_command, command_type,
                parsed_product_id, parsed_quantity,
                sentiment, moderation, processed, created_at
            ) VALUES (
                :owner_id, :live_stream_id, :order_id, :client_id,
                :source, :external_comment_id, :parent_external_id,
                :message, :attachments_json, :is_command, :command_type,
                :parsed_product_id, :parsed_quantity,
                :sentiment, :moderation, :processed, NOW()
            )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'owner_id' => $row['owner_id'],
            'live_stream_id' => $row['live_stream_id'],
            'order_id' => $row['order_id'] ?? null,
            'client_id' => $row['client_id'] ?? null,
            'source' => $row['source'] ?? 'manual',
            'external_comment_id' => $row['external_comment_id'] ?? null,
            'parent_external_id' => $row['parent_external_id'] ?? null,
            'message' => trim((string)($row['message'] ?? '')),
            'attachments_json' => $row['attachments_json'] ?? null,
            'is_command' => $row['is_command'] ?? 0,
            'command_type' => $row['command_type'] ?? null,
            'parsed_product_id' => $row['parsed_product_id'] ?? null,
            'parsed_quantity' => $row['parsed_quantity'] ?? null,
            'sentiment' => $row['sentiment'] ?? 'neu',
            'moderation' => $row['moderation'] ?? 'clean',
            'processed' => $row['processed'] ?? 0,
        ]);

        return (int)$pdo->lastInsertId();
    }


    // GET (dla danego streamu)
    public static function getComments(PDO $pdo, int $ownerId, int $liveStreamId, int $limit = 50): array
    {
        $sql = "SELECT * FROM live_comments
            WHERE owner_id = :owner_id AND live_stream_id = :live_id
            ORDER BY id DESC LIMIT :limit";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':owner_id', $ownerId, PDO::PARAM_INT);
        $stmt->bindValue(':live_id', $liveStreamId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function getAssignedClients(PDO $pdo, int $ownerId, int $liveId): array
    {
        $sql = "SELECT lt.client_id, c.name, c.phone, COUNT(*) AS total_products, SUM(lt.qty) AS total_qty
                  FROM live_temp lt
             LEFT JOIN clients c ON lt.client_id = c.id
                 WHERE lt.owner_id = :owner_id AND lt.live_id = :live_id
              GROUP BY lt.client_id
              ORDER BY c.name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['owner_id' => $ownerId, 'live_id' => $liveId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function deleteTempProduct(PDO $pdo, int $tempId, int $ownerId): bool
    {
        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare("SELECT reservation_id FROM live_temp WHERE id = :id AND owner_id = :owner_id");
            $st->execute(['id' => $tempId, 'owner_id' => $ownerId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException("Nie znaleziono pozycji");

            if (!empty($row['reservation_id'])) {
                StockReservationEngine::release($pdo, (int)$row['reservation_id']);
            }

            $pdo->prepare("DELETE FROM live_temp WHERE id = :id")->execute(['id' => $tempId]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return false;
        }
    }

    public static function updateQty(PDO $pdo, int $tempId, float $newQty, int $ownerId): bool
    {
        try {
            $pdo->beginTransaction();

            $row = $pdo->prepare("SELECT * FROM live_temp WHERE id = :id AND owner_id = :owner_id");
            $row->execute(['id' => $tempId, 'owner_id' => $ownerId]);
            $data = $row->fetch(PDO::FETCH_ASSOC);
            if (!$data) throw new RuntimeException("Nie znaleziono pozycji");

            // Aktualizacja rezerwacji (jeśli istnieje)
            if (!empty($data['reservation_id'])) {
                StockReservationEngine::updateQty($pdo, (int)$data['reservation_id'], $newQty);
            }

            $pdo->prepare("UPDATE live_temp SET qty = :qty WHERE id = :id")->execute([
                'qty' => $newQty,
                'id' => $tempId
            ]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return false;
        }
    }

    public static function quickStats(PDO $pdo, int $ownerId, int $liveId): array
    {
        $sql = "SELECT COUNT(DISTINCT client_id) AS clients,
                       SUM(qty) AS total_qty,
                       SUM(qty * price) AS total_value
                  FROM live_temp
                 WHERE owner_id = :owner_id AND live_id = :live_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['owner_id' => $ownerId, 'live_id' => $liveId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['clients' => 0, 'total_qty' => 0, 'total_value' => 0.0];
    }
}
