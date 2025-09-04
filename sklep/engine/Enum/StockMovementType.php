<?php
// engine/Enum/StockMovementType.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Typy ruchów magazynowych — stock_movements.type.
 */
final class StockMovementType
{
    public const MANUAL_ADD        = 'manual_add';
    public const MANUAL_REMOVE     = 'manual_remove';
    public const ORDER_COMMIT      = 'order_commit';
    public const ORDER_CANCEL      = 'order_cancel';
    public const RESERVATION       = 'reservation';
    public const RESERVATION_FREE  = 'reservation_free';
    public const STOCKTAKING       = 'stocktaking';
    public const SUPPLIER_PURCHASE = 'supplier_purchase';
    public const CORRECTION        = 'correction';

    public const ALL = [
        self::MANUAL_ADD,
        self::MANUAL_REMOVE,
        self::ORDER_COMMIT,
        self::ORDER_CANCEL,
        self::RESERVATION,
        self::RESERVATION_FREE,
        self::STOCKTAKING,
        self::SUPPLIER_PURCHASE,
        self::CORRECTION,
    ];

    public const LABELS = [
        self::MANUAL_ADD        => 'Ręczne dodanie',
        self::MANUAL_REMOVE     => 'Ręczne zdjęcie',
        self::ORDER_COMMIT      => 'Realizacja zamówienia',
        self::ORDER_CANCEL      => 'Anulowanie zamówienia',
        self::RESERVATION       => 'Rezerwacja',
        self::RESERVATION_FREE  => 'Zwolnienie rezerwacji',
        self::STOCKTAKING       => 'Inwentaryzacja',
        self::SUPPLIER_PURCHASE => 'Zakup od dostawcy',
        self::CORRECTION        => 'Korekta',
    ];

    public static function getLabel(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    public static function isValid(string $type): bool
    {
        return \in_array($type, self::ALL, true);
    }
}
