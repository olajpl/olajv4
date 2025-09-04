<?php
// engine/Enum/ClientStatus.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Status klienta — tabela `clients.status`.
 * Wpływa na możliwość składania zamówień, segmentację, kampanie marketingowe.
 */
final class ClientStatus
{
    public const ACTIVE    = 'active';
    public const LEAD      = 'lead';
    public const LOYAL     = 'loyal';
    public const VIP       = 'vip';
    public const BLOCKED   = 'blocked';
    public const ARCHIVED  = 'archived';

    public const ALL = [
        self::ACTIVE,
        self::LEAD,
        self::LOYAL,
        self::VIP,
        self::BLOCKED,
        self::ARCHIVED,
    ];

    public const LABELS = [
        self::ACTIVE   => 'Aktywny',
        self::LEAD     => 'Potencjalny',
        self::LOYAL    => 'Stały klient',
        self::VIP      => 'VIP',
        self::BLOCKED  => 'Zablokowany',
        self::ARCHIVED => 'Zarchiwizowany',
    ];

    public const COLORS = [
        self::ACTIVE   => 'green',
        self::LEAD     => 'gray',
        self::LOYAL    => 'blue',
        self::VIP      => 'yellow',
        self::BLOCKED  => 'red',
        self::ARCHIVED => 'gray',
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
