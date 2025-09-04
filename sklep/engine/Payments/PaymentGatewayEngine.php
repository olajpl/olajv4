<?php

declare(strict_types=1);

namespace Engine\Payments;

use PDO;

final class PaymentGatewayEngine
{
    public static function start(PDO $pdo, int $ownerId, int $paymentId): bool
    {
        // TODO: zmień payments.status -> 'started' i zainicjuj operatora
        return true;
    }
}
