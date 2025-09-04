<?php
// engine/Enum/StockStatus.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * StockStatus — status rezerwacji magazynowych (np. z LIVE, checkoutu).
 * Używany w `stock_reservations.status`.
 */
final class StockStatus
{
    public const RESERVED  = 'reserved';   // zarezerwowano (tymczasowo)
    public const COMMITTED = 'committed';  // zrealizowana (finalnie)
    public const RELEASED  = 'released';   // zwolniona

    public const ALL = [
        self::RESERVED,
        self::COMMITTED,
        self::RELEASED,
    ];

    public const LABELS = [
        self::RESERVED  => 'Zarezerwowana',
        self::COMMITTED => 'Zrealizowana',
        self::RELEASED  => 'Zwolniona',
    ];

    public const COLORS = [
        self::RESERVED  => 'yellow',
        self::COMMITTED => 'green',
        self::RELEASED  => 'gray',
    ];

    public static function getLabel(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function getColor(string $status): string
    {
        return self::COLORS[$status] ?? 'gray';
    }

    public static function isValid(string $status): bool
    {
        return \in_array($status, self::ALL, true);
    }
}
