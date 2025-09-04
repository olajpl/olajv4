<?php
// engine/Enum/PaidStatus.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * PaidStatus — order_groups.paid_status (EN)
 * Agregat statusów płatności na poziomie paczki.
 */
final class PaidStatus
{
    public const UNPAID   = 'unpaid';
    public const PARTIAL  = 'partial';
    public const PAID     = 'paid';
    public const OVERPAID = 'overpaid';

    public const ALL = [
        self::UNPAID,
        self::PARTIAL,
        self::PAID,
        self::OVERPAID,
    ];

    public const LABELS = [
        self::UNPAID   => 'Nieopłacona',
        self::PARTIAL  => 'Częściowo opłacona',
        self::PAID     => 'Opłacona',
        self::OVERPAID => 'Nadpłata',
    ];

    public const COLORS = [
        self::UNPAID   => 'gray',
        self::PARTIAL  => 'yellow',
        self::PAID     => 'green',
        self::OVERPAID => 'blue',
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
