<?php
// engine/Enum/AuditEventType.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Typy eventów logowanych w systemie (logs.event).
 */
final class AuditEventType
{
    public const LOGIN           = 'login';
    public const LOGOUT          = 'logout';
    public const VIEWED          = 'viewed';
    public const UPDATED         = 'updated';
    public const CREATED         = 'created';
    public const DELETED         = 'deleted';
    public const IMPORTED        = 'imported';
    public const EXPORTED        = 'exported';
    public const STATUS_CHANGED  = 'status_changed';
    public const STOCK_MOVED     = 'stock_moved';
    public const ATTACHED        = 'attached';
    public const DETACHED        = 'detached';
    public const ORDER_ADJUSTED  = 'order_adjusted';
    public const PAYMENT_UPDATED = 'payment_updated';
    public const SYSTEM_EVENT    = 'system_event';

    public const ALL = [
        self::LOGIN,
        self::LOGOUT,
        self::VIEWED,
        self::UPDATED,
        self::CREATED,
        self::DELETED,
        self::IMPORTED,
        self::EXPORTED,
        self::STATUS_CHANGED,
        self::STOCK_MOVED,
        self::ATTACHED,
        self::DETACHED,
        self::ORDER_ADJUSTED,
        self::PAYMENT_UPDATED,
        self::SYSTEM_EVENT,
    ];

    public const LABELS = [
        self::LOGIN           => 'Logowanie',
        self::LOGOUT          => 'Wylogowanie',
        self::VIEWED          => 'Podgląd',
        self::UPDATED         => 'Aktualizacja',
        self::CREATED         => 'Utworzenie',
        self::DELETED         => 'Usunięcie',
        self::IMPORTED        => 'Import',
        self::EXPORTED        => 'Eksport',
        self::STATUS_CHANGED  => 'Zmiana statusu',
        self::STOCK_MOVED     => 'Ruch magazynowy',
        self::ATTACHED        => 'Przypięcie',
        self::DETACHED        => 'Odpięcie',
        self::ORDER_ADJUSTED  => 'Edycja zamówienia',
        self::PAYMENT_UPDATED => 'Zmiana płatności',
        self::SYSTEM_EVENT    => 'Zdarzenie systemowe',
    ];

    public static function getLabel(string $event): string
    {
        return self::LABELS[$event] ?? $event;
    }

    public static function isValid(string $event): bool
    {
        return \in_array($event, self::ALL, true);
    }
}
