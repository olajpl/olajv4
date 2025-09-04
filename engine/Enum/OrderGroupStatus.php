<?php
// engine/Enum/OrderGroupStatus.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Status paczki / grupy zamówienia (order_groups.status)
 */
final class OrderGroupStatus
{
    public const ADD_PRODUCTS     = 'otwarta_paczka:add_products';
    public const PAYMENT_ONLY     = 'otwarta_paczka:payment_only';
    public const AWAITING_PAYMENT = 'oczekuje_na_płatność';
    public const READY_TO_SHIP    = 'gotowe_do_wysyłki';

    public const ALL = [
        self::ADD_PRODUCTS,
        self::PAYMENT_ONLY,
        self::AWAITING_PAYMENT,
        self::READY_TO_SHIP,
    ];

    public const LABELS = [
        self::ADD_PRODUCTS     => 'Dodawanie produktów',
        self::PAYMENT_ONLY     => 'Czeka na płatność',
        self::AWAITING_PAYMENT => 'Oczekuje na płatność',
        self::READY_TO_SHIP    => 'Gotowe do wysyłki',
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
