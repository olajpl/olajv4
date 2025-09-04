<?php
// engine/Supplier/SupplierEngine.php — Olaj.pl V4
declare(strict_types=1);

namespace Engine\Supplier;

use PDO;
use Throwable;

final class SupplierEngine
{
    public function __construct(private PDO $pdo) {}

    /**
     * Tworzy dostawcę. Dodatkowe pola (np. box) zapisujemy do metadata(JSON).
     * Zwraca: int supplier_id
     */
    public function create(int $ownerId, array $data): int
    {
        $name    = trim((string)($data['name'] ?? ''));
        $email   = trim((string)($data['email'] ?? ''));
        $phone   = trim((string)($data['phone'] ?? ''));
        $address = trim((string)($data['address'] ?? ''));
        $note    = trim((string)($data['note'] ?? ''));
        $box     = trim((string)($data['box'] ?? ''));

        if ($ownerId <= 0) throw new \InvalidArgumentException('ownerId<=0');
        if ($name === '') throw new \InvalidArgumentException('Brak nazwy dostawcy');

        // minimalna normalizacja telefonu
        $phone = preg_replace('/\s+/', ' ', $phone ?? '');
        // metadata
        $meta = [];
        if ($box !== '') $meta['box'] = $box;

        $sql = "INSERT INTO suppliers (owner_id, name, email, phone, address, note, metadata, created_at)
                VALUES (:oid, :name, :email, :phone, :address, :note, :metadata, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute([
            ':oid'      => $ownerId,
            ':name'     => $name,
            ':email'    => ($email !== '' ? $email : null),
            ':phone'    => ($phone !== '' ? $phone : null),
            ':address'  => ($address !== '' ? $address : null),
            ':note'     => ($note !== '' ? $note : null),
            ':metadata' => (!empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null),
        ]);
        if (!$ok) {
            $err = $stmt->errorInfo();
            throw new \RuntimeException('Nie udało się dodać dostawcy: ' . ($err[2] ?? 'SQL error'));
        }
        return (int)$this->pdo->lastInsertId();
    }
}
