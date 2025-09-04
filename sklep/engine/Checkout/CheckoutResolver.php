<?php
// engine/Checkout/CheckoutResolver.php
declare(strict_types=1);

namespace Engine\Checkout;

use PDO;

final class CheckoutResolver
{
    public static function findGroupByToken(PDO $pdo, string $token): ?array
    {
        // Szukamy po obu kolumnach – która istnieje, ta zadziała.
        $sql = "
            SELECT og.*
            FROM order_groups og
            WHERE (" . self::exists($pdo, 'order_groups', 'checkout_token') . " AND og.checkout_token = :t)
               OR (" . self::exists($pdo, 'order_groups', 'group_token') . "    AND og.group_token    = :t)
            LIMIT 1
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':t' => $token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function exists(PDO $pdo, string $table, string $col): string
    {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
            $st->execute([':col' => $col]);
            return $st->fetch() ? '1=1' : '0=1';
        } catch (\Throwable $__) {
            return '0=1';
        }
    }
}
