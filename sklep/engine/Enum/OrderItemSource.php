<?php
// engine/Enum/OrderItemSource.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Źródło dodania produktu do zamówienia:
 * używane w order_items.source_type
 */
final class OrderItemSource
{
    public const SHOP     = 'shop';       // Sklep frontend
    public const PARSER   = 'parser';     // Parser (np. "daj 123")
    public const MANUAL   = 'manual';     // Panel admina ręcznie
    public const LIVE     = 'live';       // Transmisje LIVE
    public const API      = 'api';        // Integracja API (np. hurtownie)
    public const IMPORT   = 'import';     // Import CSV/Excel

    public const ALL = [
        self::SHOP,
        self::PARSER,
        self::MANUAL,
        self::LIVE,
        self::API,
        self::IMPORT,
    ];

    public const LABELS = [
        self::SHOP   => 'Sklep',
        self::PARSER => 'Parser',
        self::MANUAL => 'Panel',
        self::LIVE   => 'Live',
        self::API    => 'API',
        self::IMPORT => 'Import',
    ];

    public static function getLabel(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    public static function isValid(string $key): bool
    {
        return \in_array($key, self::ALL, true);
    }
}
