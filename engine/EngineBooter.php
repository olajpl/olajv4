<?php
// engine/EngineBooter.php — wspólny bootloader silników (Olaj.pl V4)
declare(strict_types=1);

namespace Engine;

use PDO;
use Engine\Log\LogEngine;

final class EngineBooter
{
    public static function boot(PDO $pdo, int $ownerId): void
    {
        // Boot logg() jeśli jeszcze nie było
        if (!LogEngine::instance()) {
            LogEngine::boot($pdo, $ownerId);
        }
    }
}
