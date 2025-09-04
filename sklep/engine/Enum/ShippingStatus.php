<?php
// engine/Enum/ShippingStatus.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * ShippingStatus — statusy realizacji przesyłek.
 * Może dotyczyć etykiet, grup zamówień lub całych zamówień.
 */
final class ShippingStatus
{
    public const NONE       = 'none';         // brak statusu
    public const READY      = 'ready';        // gotowe do wysyłki
    public const LABELLED   = 'labelled';     // etykieta utworzona
    public const HANDED_OFF = 'handed_off';   // przekazane kurierowi
    public const IN_TRANSIT = 'in_transit';   // w drodze
    public const DELIVERED  = 'delivered';    // doręczone
    public const RETURNED   = 'returned';     // zwrócone

    public const ALL = [
        self::NONE,
        self::READY,
        self::LABELLED,
        self::HANDED_OFF,
        self::IN_TRANSIT,
        self::DELIVERED,
        self::RETURNED,
    ];

    public const LABELS = [
        self::NONE       => 'Brak',
        self::READY      => 'Gotowe do wysyłki',
        self::LABELLED   => 'Etykieta utworzona',
        self::HANDED_OFF => 'Przekazane kurierowi',
        self::IN_TRANSIT => 'W transporcie',
        self::DELIVERED  => 'Dostarczone',
        self::RETURNED   => 'Zwrócone',
    ];

    public const COLORS = [
        self::NONE       => 'gray',
        self::READY      => 'yellow',
        self::LABELLED   => 'blue',
        self::HANDED_OFF => 'indigo',
        self::IN_TRANSIT => 'cyan',
        self::DELIVERED  => 'green',
        self::RETURNED   => 'red',
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
