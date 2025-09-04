<?php
// engine/Enum/CartItemSourceType.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * CartItemSourceType — źródło pozycji (katalogowy czy customowy)
 * Używane w: cart_items, order_items, live_temp
 */
final class CartItemSourceType
{
    public const CATALOG = 'catalog';
    public const CUSTOM  = 'custom';

    public const ALL = [
        self::CATALOG,
        self::CUSTOM,
    ];

    public const LABELS = [
        self::CATALOG => 'Katalogowy',
        self::CUSTOM  => 'Ręczny (custom)',
    ];

    public const COLORS = [
        self::CATALOG => 'green',
        self::CUSTOM  => 'orange',
    ];

    public static function getLabel(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    public static function getColor(string $type): string
    {
        return self::COLORS[$type] ?? 'gray';
    }

    public static function isValid(string $type): bool
    {
        return \in_array($type, self::ALL, true);
    }
}
