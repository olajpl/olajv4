<?php
// engine/Enum/MessageStatus.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Status wiadomości w CW.
 * Używane w: messages.status
 */
final class MessageStatus
{
    public const NEW       = 'new';        // nowa, jeszcze nieprzetworzona
    public const SENT      = 'sent';       // wysłana poprawnie
    public const FAILED    = 'failed';     // błąd wysyłki
    public const ERROR     = 'error';      // błąd systemowy
    public const SKIPPED   = 'skipped';    // pominięta (np. wiadomość nieobsługiwana)
    public const QUEUED    = 'queued';     // w kolejce do wysłania
    public const CANCELLED = 'cancelled';  // anulowana (np. przez operatora)
    public const IGNORED   = 'ignored';    // zignorowana (np. przez filtr)
    public const DUPLICATE = 'duplicate';  // duplikat (np. webhooków)
    public const DELETED   = 'deleted';    // usunięta ręcznie

    public const ALL = [
        self::NEW,
        self::SENT,
        self::FAILED,
        self::ERROR,
        self::SKIPPED,
        self::QUEUED,
        self::CANCELLED,
        self::IGNORED,
        self::DUPLICATE,
        self::DELETED,
    ];

    public const LABELS = [
        self::NEW       => 'Nowa',
        self::SENT      => 'Wysłana',
        self::FAILED    => 'Błąd wysyłki',
        self::ERROR     => 'Błąd systemowy',
        self::SKIPPED   => 'Pominięta',
        self::QUEUED    => 'W kolejce',
        self::CANCELLED => 'Anulowana',
        self::IGNORED   => 'Zignorowana',
        self::DUPLICATE => 'Duplikat',
        self::DELETED   => 'Usunięta',
    ];

    public const COLORS = [
        self::NEW       => 'gray',
        self::SENT      => 'green',
        self::FAILED    => 'red',
        self::ERROR     => 'red',
        self::SKIPPED   => 'yellow',
        self::QUEUED    => 'blue',
        self::CANCELLED => 'gray',
        self::IGNORED   => 'indigo',
        self::DUPLICATE => 'orange',
        self::DELETED   => 'gray',
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
