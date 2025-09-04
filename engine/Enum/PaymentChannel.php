<?php
// engine/Enum/PaymentChannel.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Kanał płatności – rodzaj metody płatności
 * (np. bank transfer, cash, paynow, cod)
 */
final class PaymentChannel
{
    public const TRANSFER = 'transfer';
    public const BLIK     = 'blik';
    public const CARD     = 'card';
    public const PAYNOW   = 'paynow';
    public const CASH     = 'cash';
    public const COD      = 'cod'; // cash on delivery
    public const OTHER    = 'other';

    public const ALL = [
        self::TRANSFER,
        self::BLIK,
        self::CARD,
        self::PAYNOW,
        self::CASH,
        self::COD,
        self::OTHER,
    ];

    public const LABELS = [
        self::TRANSFER => 'Przelew',
        self::BLIK     => 'BLIK',
        self::CARD     => 'Karta',
        self::PAYNOW   => 'PayNow',
        self::CASH     => 'Gotówka',
        self::COD      => 'Pobranie',
        self::OTHER    => 'Inna metoda',
    ];

    public static function getLabel(string $channel): string
    {
        return self::LABELS[$channel] ?? $channel;
    }

    public static function isValid(string $channel): bool
    {
        return \in_array($channel, self::ALL, true);
    }
}
