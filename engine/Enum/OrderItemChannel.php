<?php
// engine/Enum/OrderItemChannel.php

declare(strict_types=1);

namespace Engine\Enum;

/**
 * Kanał, przez który klient kupił produkt / dodał do paczki.
 * Używany w order_items.channel i logice źródła zakupu.
 */
final class OrderItemChannel
{
    public const MESSENGER    = 'messenger';      // Wiadomość prywatna FB
    public const COMMENT_FB   = 'comment.fb';     // Komentarz na Facebooku
    public const COMMENT_IG   = 'comment.ig';     // Komentarz na Instagramie
    public const DM_IG        = 'dm.ig';          // Wiadomość prywatna IG
    public const LIVE_FB      = 'live.fb';        // Zakup z transmisji live
    public const SHOP_WEB     = 'shop.web';       // Front sklepu (www)
    public const SHOP_APP     = 'shop.app';       // Aplikacja mobilna / PWA
    public const ADMIN_PANEL  = 'admin.panel';    // Operator ręcznie dodał

    public const ALL = [
        self::MESSENGER,
        self::COMMENT_FB,
        self::COMMENT_IG,
        self::DM_IG,
        self::LIVE_FB,
        self::SHOP_WEB,
        self::SHOP_APP,
        self::ADMIN_PANEL,
    ];

    public const LABELS = [
        self::MESSENGER   => 'Messenger',
        self::COMMENT_FB  => 'Komentarz Facebook',
        self::COMMENT_IG  => 'Komentarz Instagram',
        self::DM_IG       => 'DM Instagram',
        self::LIVE_FB     => 'LIVE Facebook',
        self::SHOP_WEB    => 'Sklep WWW',
        self::SHOP_APP    => 'Aplikacja mobilna',
        self::ADMIN_PANEL => 'Panel administratora',
    ];

    public const COLORS = [
        self::MESSENGER   => 'blue',
        self::COMMENT_FB  => 'indigo',
        self::COMMENT_IG  => 'pink',
        self::DM_IG       => 'pink',
        self::LIVE_FB     => 'purple',
        self::SHOP_WEB    => 'green',
        self::SHOP_APP    => 'teal',
        self::ADMIN_PANEL => 'gray',
    ];

    public const ICONS = [
        self::MESSENGER   => 'message-circle',
        self::COMMENT_FB  => 'facebook',
        self::COMMENT_IG  => 'instagram',
        self::DM_IG       => 'mail',
        self::LIVE_FB     => 'video',
        self::SHOP_WEB    => 'shopping-cart',
        self::SHOP_APP    => 'smartphone',
        self::ADMIN_PANEL => 'settings',
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
