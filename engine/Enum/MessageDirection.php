<?php
// engine/Enum/MessageDirection.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Kierunek wiadomości — IN (od klienta) / OUT (do klienta)
 * Używane w: messages.direction
 */
final class MessageDirection
{
    public const IN  = 'in';   // od klienta
    public const OUT = 'out';  // do klienta

    public const ALL = [
        self::IN,
        self::OUT,
    ];

    public const LABELS = [
        self::IN  => 'Od klienta',
        self::OUT => 'Do klienta',
    ];

    public const COLORS = [
        self::IN  => 'gray',
        self::OUT => 'blue',
    ];

    public static function getLabel(string $direction): string
    {
        return self::LABELS[$direction] ?? $direction;
    }

    public static function getColor(string $direction): string
    {
        return self::COLORS[$direction] ?? 'gray';
    }

    public static function isValid(string $direction): bool
    {
        return \in_array($direction, self::ALL, true);
    }
}
