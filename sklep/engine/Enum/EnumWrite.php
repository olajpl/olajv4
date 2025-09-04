<?php
// engine/Enum/EnumWrite.php
declare(strict_types=1);

namespace Engine\Enum;

use PDO;

final class EnumWrite
{
    public static function setOrderStatus(PDO $pdo, int $orderId, string $statusKey): void
    {
        EnumRepo::ensureAllowed($pdo, 'order_status', $statusKey);

        $sql = "UPDATE orders
                   SET order_status_key = :k,
                       order_status_set_key = 'order_status',
                       order_status = :enum  -- stary ENUM (dual-write)
                 WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':k' => $statusKey, ':enum' => $statusKey, ':id' => $orderId]);
    }

    public static function setPaymentStatus(PDO $pdo, int $paymentId, string $statusKey): void
    {
        EnumRepo::ensureAllowed($pdo, 'payment_status', $statusKey);

        $sql = "UPDATE payments
                   SET status_key = :k,
                       status_set_key = 'payment_status',
                       status = :enum
                 WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':k' => $statusKey, ':enum' => $statusKey, ':id' => $paymentId]);
    }

    public static function setGroupPaidStatus(PDO $pdo, int $groupId, string $statusKey): void
    {
        EnumRepo::ensureAllowed($pdo, 'group_paid_status', $statusKey);

        $sql = "UPDATE order_groups
                   SET paid_status_key = :k,
                       paid_status_set_key = 'group_paid_status',
                       paid_status = :enum
                 WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':k' => $statusKey, ':enum' => $statusKey, ':id' => $groupId]);
    }
}
