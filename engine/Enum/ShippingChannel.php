<?php
// engine/Enum/ShippingChannel.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Kanały wysyłki – identyfikacja rodzaju przewoźnika / sposobu dostawy
 */
final class ShippingChannel
{
    public const INPOST_LOCKER = 'inpost.locker';
    public const INPOST_COURIER = 'inpost.courier';
    public const DPD            = 'dpd';
    public const PICKUP         = 'pickup';
    public const CUSTOM         = 'custom';

    public const ALL = [
        self::INPOST_LOCKER,
        self::INPOST_COURIER,
        self::DPD,
        self::PICKUP,
        self::CUSTOM,
    ];

    public const LABELS = [
        self::INPOST_LOCKER  => 'InPost Paczkomat',
        self::INPOST_COURIER => 'InPost Kurier',
        self::DPD            => 'DPD',
        self::PICKUP         => 'Odbiór osobisty',
        self::CUSTOM         => 'Inna dostawa',
    ];

    public const ICONS = [
        self::INPOST_LOCKER  => 'package',
        self::INPOST_COURIER => 'truck',
        self::DPD            => 'truck',
        self::PICKUP         => 'store',
        self::CUSTOM         => 'truck',
    ];

    public static function getLabel(string $channel): string
    {
        return self::LABELS[$channel] ?? $channel;
    }

    public static function getIcon(string $channel): string
    {
        return self::ICONS[$channel] ?? 'truck';
    }

    public static function isValid(string $channel): bool
    {
        return \in_array($channel, self::ALL, true);
    }
}
