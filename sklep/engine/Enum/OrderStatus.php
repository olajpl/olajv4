<?php
// engine/Enum/OrderStatus.php

declare(strict_types=1);

namespace Engine\Enum;

enum OrderStatus: string
{
    case NEW = 'new';
    case OPEN_PACKAGE = 'open_package';
    case OPEN_PACKAGE_ADD_PRODUCTS = 'open_package:add_products';
    case OPEN_PACKAGE_PAYMENT_ONLY = 'open_package:payment_only';
    case AWAITING_PAYMENT = 'awaiting_payment';
    case READY_TO_SHIP = 'ready_to_ship';
    case SHIPPED = 'shipped';
    case COMPLETED = 'completed';
    case ARCHIVED = 'archived';
    case CANCELLED = 'cancelled';

    public static function labels(): array
    {
        return [
            self::NEW->value => 'Nowe',
            self::OPEN_PACKAGE->value => 'Otwarta paczka',
            self::OPEN_PACKAGE_ADD_PRODUCTS->value => 'Dodawanie produktów',
            self::OPEN_PACKAGE_PAYMENT_ONLY->value => 'Zamknięta - płatność',
            self::AWAITING_PAYMENT->value => 'Czeka na płatność',
            self::READY_TO_SHIP->value => 'Do wysyłki',
            self::SHIPPED->value => 'Wysłane',
            self::COMPLETED->value => 'Zrealizowane',
            self::ARCHIVED->value => 'Zarchiwizowane',
            self::CANCELLED->value => 'Anulowane',
        ];
    }

    public static function getLabel(string $status): string
    {
        return self::labels()[$status] ?? $status;
    }

    public static function isOpen(string $status): bool
    {
        return \in_array($status, [
            self::OPEN_PACKAGE->value,
            self::OPEN_PACKAGE_ADD_PRODUCTS->value,
            self::OPEN_PACKAGE_PAYMENT_ONLY->value,
        ], true);
    }

    public const ALL = [
        self::NEW->value,
        self::OPEN_PACKAGE->value,
        self::OPEN_PACKAGE_ADD_PRODUCTS->value,
        self::OPEN_PACKAGE_PAYMENT_ONLY->value,
        self::AWAITING_PAYMENT->value,
        self::READY_TO_SHIP->value,
        self::SHIPPED->value,
        self::COMPLETED->value,
        self::ARCHIVED->value,
        self::CANCELLED->value,
    ];
}
