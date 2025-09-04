<?php

declare(strict_types=1);

namespace Engine\CentralMessaging\Channels;

use PDO;

final class Sms
{
    public static function send(PDO $pdo, array $message): bool
    {
        // TODO: provider SMS; mock sukcesu
        return true;
    }
}
