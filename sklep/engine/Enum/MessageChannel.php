<?php
// engine/Enum/MessageChannel.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Kanały komunikacji wiadomości w CW.
 * Używane m.in. w: messages.channel, cw_queue.channel
 */
final class MessageChannel
{
    public const MESSENGER = 'messenger';
    public const SMS       = 'sms';
    public const EMAIL     = 'email';
    public const COMMENT   = 'comment';  // np. komentarze z FB/IG
    public const PUSH      = 'push';

    public const ALL = [
        self::MESSENGER,
        self::SMS,
        self::EMAIL,
        self::COMMENT,
        self::PUSH,
    ];

    public const LABELS = [
        self::MESSENGER => 'Messenger',
        self::SMS       => 'SMS',
        self::EMAIL     => 'E-mail',
        self::COMMENT   => 'Komentarz',
        self::PUSH      => 'Push',
    ];

    public const COLORS = [
        self::MESSENGER => 'blue',
        self::SMS       => 'teal',
        self::EMAIL     => 'orange',
        self::COMMENT   => 'indigo',
        self::PUSH      => 'lime',
    ];

    public const ICONS = [
        self::MESSENGER => 'message-circle',
        self::SMS       => 'smartphone',
        self::EMAIL     => 'mail',
        self::COMMENT   => 'message-square',
        self::PUSH      => 'bell',
    ];

    public static function getLabel(string $channel): string
    {
        return self::LABELS[$channel] ?? $channel;
    }

    public static function getColor(string $channel): string
    {
        return self::COLORS[$channel] ?? 'gray';
    }

    public static function getIcon(string $channel): string
    {
        return self::ICONS[$channel] ?? 'help-circle';
    }

    public static function isValid(string $channel): bool
    {
        return \in_array($channel, self::ALL, true);
    }
}
