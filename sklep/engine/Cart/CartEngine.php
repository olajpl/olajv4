<?php
// engine/Cart/CartEngine.php — Olaj V4
declare(strict_types=1);

namespace Engine\Cart;

use PDO;
use PDOException;
use RuntimeException;

require_once __DIR__ . '/../Log/LogEngine.php'; // logg()

final class CartEngine
{
    /**
     * Dodaje produkt do koszyka (lub zwiększa ilość)
     */
    public static function addItem(PDO $pdo, int $ownerId, string $sessionOrClientToken, int $productId, float $qty, float $unitPrice, ?float $vatRate = null, string $sourceType = 'shop'): void
    {
        if ($qty <= 0) {
            throw new RuntimeException("Ilość musi być większa niż 0");
        }

        $whereColumn = self::detectTokenColumn($sessionOrClientToken);
        $sql = "
            INSERT INTO cart_items (owner_id, {$whereColumn}, product_id, qty, unit_price, vat_rate, source_type)
            VALUES (:oid, :token, :pid, :qty, :price, :vat, :source)
            ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':oid'    => $ownerId,
            ':token'  => $sessionOrClientToken,
            ':pid'    => $productId,
            ':qty'    => $qty,
            ':price'  => $unitPrice,
            ':vat'    => $vatRate,
            ':source' => $sourceType,
        ]);

        \logg('info', 'cart.add', 'Produkt dodany do koszyka', compact('ownerId', 'productId', 'qty', 'unitPrice', 'vatRate', 'sourceType'));
    }

    /**
     * Aktualizuje ilość w koszyku (nadpisuje qty)
     */
    public static function updateQuantity(PDO $pdo, int $ownerId, string $sessionOrClientToken, int $productId, float $qty): void
    {
        $whereColumn = self::detectTokenColumn($sessionOrClientToken);

        if ($qty <= 0) {
            self::removeItem($pdo, $ownerId, $sessionOrClientToken, $productId);
            return;
        }

        $sql = "UPDATE cart_items SET qty = :qty WHERE owner_id = :oid AND {$whereColumn} = :token AND product_id = :pid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':qty'   => $qty,
            ':oid'   => $ownerId,
            ':token' => $sessionOrClientToken,
            ':pid'   => $productId,
        ]);

        \logg('info', 'cart.update_qty', 'Zmieniono ilość w koszyku', compact('ownerId', 'productId', 'qty'));
    }

    /**
     * Usuwa produkt z koszyka
     */
    public static function removeItem(PDO $pdo, int $ownerId, string $sessionOrClientToken, int $productId): void
    {
        $whereColumn = self::detectTokenColumn($sessionOrClientToken);
        $sql = "DELETE FROM cart_items WHERE owner_id = :oid AND {$whereColumn} = :token AND product_id = :pid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':oid'   => $ownerId,
            ':token' => $sessionOrClientToken,
            ':pid'   => $productId,
        ]);

        \logg('info', 'cart.remove', 'Usunięto produkt z koszyka', compact('ownerId', 'productId'));
    }

    /**
     * Czyści cały koszyk
     */
    public static function clearCart(PDO $pdo, int $ownerId, string $sessionOrClientToken): void
    {
        $whereColumn = self::detectTokenColumn($sessionOrClientToken);
        $sql = "DELETE FROM cart_items WHERE owner_id = :oid AND {$whereColumn} = :token";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':oid'   => $ownerId,
            ':token' => $sessionOrClientToken,
        ]);

        \logg('info', 'cart.clear', 'Wyczyszczono koszyk', compact('ownerId'));
    }

    /**
     * Pobiera zawartość koszyka
     */
    public static function getCartItems(PDO $pdo, int $ownerId, string $sessionOrClientToken): array
    {
        $whereColumn = self::detectTokenColumn($sessionOrClientToken);
        $sql = "SELECT * FROM cart_items WHERE owner_id = :oid AND {$whereColumn} = :token ORDER BY id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':oid'   => $ownerId,
            ':token' => $sessionOrClientToken,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Łączy koszyk gościa z kontem klienta po zalogowaniu
     */
    public static function mergeGuestToClient(PDO $pdo, int $ownerId, string $cartSid, string $clientToken): void
    {
        if ($cartSid === $clientToken) return;

        $sql = "
    INSERT INTO cart_items (owner_id, client_token, product_id, qty, unit_price, vat_rate, source_type)
    SELECT c.owner_id, :client, c.product_id, c.qty, c.unit_price, c.vat_rate, c.source_type
    FROM cart_items c
    WHERE c.owner_id = :oid AND c.cart_sid = :sid
";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':oid'    => $ownerId,
            ':sid'    => $cartSid,
            ':client' => $clientToken,
        ]);

        // Usuń wpisy po migracji
        $stmt = $pdo->prepare("DELETE FROM cart_items WHERE owner_id = :oid AND cart_sid = :sid");
        $stmt->execute([
            ':oid' => $ownerId,
            ':sid' => $cartSid,
        ]);

        \logg('info', 'cart.merge', 'Połączono koszyk gościa z kontem klienta', compact('ownerId', 'cartSid', 'clientToken'));
    }

    /**
     * Detekcja kolumny (cart_sid vs client_token)
     */
    private static function detectTokenColumn(string $token): string
    {
        return str_starts_with($token, 'cli-') ? 'client_token' : 'cart_sid';
    }
}
