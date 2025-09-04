<?php
// engine/Enum/ProductTagColor.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Kolory tagów produktów — wykorzystywane do oznaczeń w panelu/adminie.
 * Zgodne z Tailwind CSS (np. `bg-red-100 text-red-800`).
 */
final class ProductTagColor
{
    public const GRAY     = 'gray';
    public const RED      = 'red';
    public const ORANGE   = 'orange';
    public const YELLOW   = 'yellow';
    public const GREEN    = 'green';
    public const TEAL     = 'teal';
    public const BLUE     = 'blue';
    public const INDIGO   = 'indigo';
    public const PURPLE   = 'purple';
    public const PINK     = 'pink';

    public const ALL = [
        self::GRAY,
        self::RED,
        self::ORANGE,
        self::YELLOW,
        self::GREEN,
        self::TEAL,
        self::BLUE,
        self::INDIGO,
        self::PURPLE,
        self::PINK,
    ];

    public const LABELS = [
        self::GRAY   => 'Szary',
        self::RED    => 'Czerwony',
        self::ORANGE => 'Pomarańczowy',
        self::YELLOW => 'Żółty',
        self::GREEN  => 'Zielony',
        self::TEAL   => 'Morski',
        self::BLUE   => 'Niebieski',
        self::INDIGO => 'Indygo',
        self::PURPLE => 'Fioletowy',
        self::PINK   => 'Różowy',
    ];

    public static function getLabel(string $color): string
    {
        return self::LABELS[$color] ?? $color;
    }

    public static function isValid(string $color): bool
    {
        return \in_array($color, self::ALL, true);
    }
}
