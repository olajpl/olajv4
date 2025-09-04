<?php
// engine/Enum/ReservationStatus.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Statusy rezerwacji — stock_reservations.status.
 * Określają cykl życia rezerwacji: od zarezerwowania do zwolnienia lub zużycia.
 */
final class ReservationStatus
{
    public const RESERVED  = 'reserved';
    public const RELEASED  = 'released';
    public const COMMITTED = 'committed';

    public const ALL = [
        self::RESERVED,
        self::RELEASED,
        self::COMMITTED,
    ];

    public const LABELS = [
        self::RESERVED  => 'Zarezerwowane',
        self::RELEASED  => 'Zwolnione',
        self::COMMITTED => 'Zużyte (zrealizowane)',
    ];

    public static function getLabel(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function isValid(string $status): bool
    {
        return \in_array($status, self::ALL, true);
    }
}
<?php
// engine/Enum/ReservationStatus.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Statusy rezerwacji — stock_reservations.status.
 * Określają cykl życia rezerwacji: od zarezerwowania do zwolnienia lub zużycia.
 */
final class ReservationStatus
{
    public const RESERVED  = 'reserved';
    public const RELEASED  = 'released';
    public const COMMITTED = 'committed';

    public const ALL = [
        self::RESERVED,
        self::RELEASED,
        self::COMMITTED,
    ];

    public const LABELS = [
        self::RESERVED  => 'Zarezerwowane',
        self::RELEASED  => 'Zwolnione',
        self::COMMITTED => 'Zużyte (zrealizowane)',
    ];

    public static function getLabel(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function isValid(string $status): bool
    {
        return \in_array($status, self::ALL, true);
    }
}
