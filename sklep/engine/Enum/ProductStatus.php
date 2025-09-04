<?php
// engine/Enum/ProductStatus.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Status produktu – określa czy produkt jest aktywny, wycofany itd.
 */
final class ProductStatus
{
    public const ACTIVE     = 'active';
    public const INACTIVE   = 'inactive';
    public const ARCHIVED   = 'archived';
    public const DELETED    = 'deleted';

    public const ALL = [
        self::ACTIVE,
        self::INACTIVE,
        self::ARCHIVED,
        self::DELETED,
    ];

    public const LABELS = [
        self::ACTIVE   => 'Aktywny',
        self::INACTIVE => 'Nieaktywny',
        self::ARCHIVED => 'Zarchiwizowany',
        self::DELETED  => 'Usunięty',
    ];

    public const COLORS = [
        self::ACTIVE   => 'green',
        self::INACTIVE => 'yellow',
        self::ARCHIVED => 'gray',
        self::DELETED  => 'red',
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
