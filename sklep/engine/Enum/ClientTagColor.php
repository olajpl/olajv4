<?php
// engine/Enum/ClientTagColor.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Kolory tagów klienta — client_tags.color.
 * Używane w podglądzie klienta, listach, filtrach i UI.
 */
final class ClientTagColor
{
    public const GRAY     = 'gray';
    public const RED      = 'red';
    public const YELLOW   = 'yellow';
    public const GREEN    = 'green';
    public const BLUE     = 'blue';
    public const INDIGO   = 'indigo';
    public const PURPLE   = 'purple';
    public const PINK     = 'pink';

    public const ALL = [
        self::GRAY,
        self::RED,
        self::YELLOW,
        self::GREEN,
        self::BLUE,
        self::INDIGO,
        self::PURPLE,
        self::PINK,
    ];

    public static function isValid(string $color): bool
    {
        return \in_array($color, self::ALL, true);
    }
}
