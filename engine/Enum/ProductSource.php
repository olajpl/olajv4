<?php
// engine/Enum/ProductSource.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Źródło pochodzenia produktu – np. z magazynu, ręcznie dodany, od dostawcy zewnętrznego.
 */
final class ProductSource
{
    public const CATALOG     = 'catalog';
    public const CUSTOM      = 'custom';
    public const SUPPLIER    = 'supplier';
    public const INTEGRATION = 'integration';

    public const ALL = [
        self::CATALOG,
        self::CUSTOM,
        self::SUPPLIER,
        self::INTEGRATION,
    ];

    public const LABELS = [
        self::CATALOG     => 'Katalog',
        self::CUSTOM      => 'Własny (custom)',
        self::SUPPLIER    => 'Zewnętrzny dostawca',
        self::INTEGRATION => 'Integracja z systemem',
    ];

    public const COLORS = [
        self::CATALOG     => 'blue',
        self::CUSTOM      => 'yellow',
        self::SUPPLIER    => 'green',
        self::INTEGRATION => 'purple',
    ];

    public static function getLabel(string $source): string
    {
        return self::LABELS[$source] ?? $source;
    }

    public static function getColor(string $source): string
    {
        return self::COLORS[$source] ?? 'gray';
    }

    public static function isValid(string $source): bool
    {
        return \in_array($source, self::ALL, true);
    }
}
