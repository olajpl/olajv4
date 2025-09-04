<?php

declare(strict_types=1);

namespace Engine\CentralMessaging\Channels;

use PDO;

final class Email
{
    public static function send(PDO $pdo, array $message): bool
    {
        // TODO: SMTP/API; mock sukcesu
        return true;
    }
}
