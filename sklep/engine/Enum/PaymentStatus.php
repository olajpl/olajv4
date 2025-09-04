<?php
// engine/Enum/PaymentStatus.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * PaymentStatus — status pojedynczej płatności (tabela `payments`).
 * Reprezentuje stan konkretnej próby płatności (np. bramka online).
 */
final class PaymentStatus
{
    public const DRAFT     = 'draft';     // utworzona, nie rozpoczęta
    public const STARTED   = 'started';   // rozpoczęta, np. kliknięto "zapłać"
    public const PENDING   = 'pending';   // oczekuje (np. BLIK push)
    public const PAID      = 'paid';      // zapłacona
    public const FAILED    = 'failed';    // błąd / brak środków
    public const CANCELLED = 'cancelled'; // klient anulował

    public const ALL = [
        self::DRAFT,
        self::STARTED,
        self::PENDING,
        self::PAID,
        self::FAILED,
        self::CANCELLED,
    ];

    public const LABELS = [
        self::DRAFT     => 'Szkic',
        self::STARTED   => 'Rozpoczęta',
        self::PENDING   => 'Oczekująca',
        self::PAID      => 'Zapłacona',
        self::FAILED    => 'Nieudana',
        self::CANCELLED => 'Anulowana',
    ];

    public const COLORS = [
        self::DRAFT     => 'gray',
        self::STARTED   => 'blue',
        self::PENDING   => 'amber',
        self::PAID      => 'green',
        self::FAILED    => 'red',
        self::CANCELLED => 'zinc',
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
