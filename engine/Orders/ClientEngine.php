<?php
// engine/orders/ClientEngine.php — Olaj.pl V4 (z obsługą deleted_at)
declare(strict_types=1);

namespace Engine\Orders;

use PDO;
use Throwable;
use RuntimeException;
use Engine\Enum\Column;

if (!\function_exists('logg')) {
    function logg(string $level, string $channel, string $message, array $context = [], array $extra = []): void
    {
        error_log('[logg-fallback] ' . json_encode(compact('level', 'channel', 'message', 'context', 'extra'), JSON_UNESCAPED_UNICODE));
    }
}

final class ClientEngine
{
    public function __construct(private PDO $pdo) {}

    public function getClient(int $clientId, ?int $ownerId = null): array
    {
        $sql = "SELECT id, owner_id, token, name, email, phone, last_seen, registered_at, updated_at
                  FROM clients
                 WHERE id = :id AND deleted_at IS NULL";
        if ($ownerId !== null) {
            $sql .= " AND owner_id = :owner_id";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $clientId, PDO::PARAM_INT);
        if ($ownerId !== null) {
            $stmt->bindValue(':owner_id', $ownerId, PDO::PARAM_INT);
        }

        $stmt->execute();
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            throw new RuntimeException("Client not found (ID: $clientId)");
        }

        return $client;
    }

    public function findClientByToken(string $token, ?int $ownerId = null): ?array
    {
        $sql = "SELECT id, owner_id, token FROM clients
                 WHERE token = :token AND deleted_at IS NULL";
        if ($ownerId !== null) {
            $sql .= " AND owner_id = :owner_id";
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':token', $token);
        if ($ownerId !== null) {
            $stmt->bindValue(':owner_id', $ownerId);
        }

        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createClient(int $ownerId, string $token): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO clients (owner_id, token, registered_at)
                 VALUES (:oid, :token, NOW())"
            );
            $stmt->execute([
                'oid'   => $ownerId,
                'token' => $token,
            ]);

            $clientId = (int)$this->pdo->lastInsertId();

            logg('info', 'clients.create', 'client.created', [
                'owner_id'  => $ownerId,
                'client_id' => $clientId,
                'token'     => $token,
            ]);

            return $clientId;
        } catch (Throwable $e) {
            logg('error', 'clients.create', 'create.error', [
                'owner_id' => $ownerId,
                'token'    => $token,
                '_ex'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function updateLastSeen(int $clientId): void
    {
        $stmt = $this->pdo->prepare("UPDATE clients SET last_seen = NOW() WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute(['id' => $clientId]);

        logg('info', 'clients.activity', 'last_seen.updated', [
            'client_id' => $clientId,
        ]);
    }

    public function softDelete(int $clientId): void
    {
        $stmt = $this->pdo->prepare("UPDATE clients SET deleted_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $clientId]);

        logg('info', 'clients.delete', 'soft_deleted', [
            'client_id' => $clientId,
        ]);
    }

    public function restore(int $clientId): void
    {
        $stmt = $this->pdo->prepare("UPDATE clients SET deleted_at = NULL WHERE id = :id");
        $stmt->execute(['id' => $clientId]);

        logg('info', 'clients.restore', 'restored', [
            'client_id' => $clientId,
        ]);
    }

    /**
     * Znajdź klienta po tokenie — jeśli nie istnieje, sprawdź, czy jest childem i zwróć mastera.
     */
    public function findClientByTokenIncludingMaster(string $token, ?int $ownerId = null): ?array
    {
        // Krok 1: Szukaj klienta po tokenie
        $sql = "SELECT id, owner_id, token, master_client_id
              FROM clients
             WHERE token = :token AND deleted_at IS NULL";
        if ($ownerId !== null) {
            $sql .= " AND owner_id = :owner_id";
        }
        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':token', $token);
        if ($ownerId !== null) {
            $stmt->bindValue(':owner_id', $ownerId);
        }
        $stmt->execute();
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) return null;

        // Krok 2: Jeśli ma master_client_id → pobierz mastera
        if (!empty($client['master_client_id'])) {
            return $this->getClient((int)$client['master_client_id'], $ownerId);
        }

        // Krok 3: Zwróć klienta bazowego
        return $client;
    }
    /**
     * Pobierz klienta po ID — jeśli ma master_client_id, zwróć mastera.
     */
    public function getClientIncludingMaster(int $clientId, ?int $ownerId = null): array
    {
        $sql = "SELECT id, owner_id, token, master_client_id
              FROM clients
             WHERE id = :id AND deleted_at IS NULL";
        if ($ownerId !== null) {
            $sql .= " AND owner_id = :owner_id";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $clientId, PDO::PARAM_INT);
        if ($ownerId !== null) {
            $stmt->bindValue(':owner_id', $ownerId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            throw new RuntimeException("Client not found (ID: $clientId)");
        }

        if (!empty($client['master_client_id'])) {
            return $this->getClient((int)$client['master_client_id'], $ownerId);
        }

        return $client;
    }
}
