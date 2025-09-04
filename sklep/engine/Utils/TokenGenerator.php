<?php

declare(strict_types=1);

namespace Engine\Utils;

final class TokenGenerator
{
    /** checkout_token np. olaj-1a2b3c... */
    public static function checkoutToken(): string
    {
        return 'olaj-' . bin2hex(random_bytes(8));
    }

    /** group_token np. grp-1a2b3c... */
    public static function groupToken(): string
    {
        return 'grp-' . bin2hex(random_bytes(8));
    }

    /** Idempotency / webhook keys (domyślnie dla FB) */
    public static function webhookToken(string $prefix = 'fb_'): string
    {
        return $prefix . bin2hex(random_bytes(16));
    }

    /** generyczne tokeny dla różnych use-case */
    public static function custom(string $prefix = 'tok', int $bytes = 8): string
    {
        return $prefix . '-' . bin2hex(random_bytes($bytes));
    }
}
