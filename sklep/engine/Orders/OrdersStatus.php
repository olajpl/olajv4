<?php
// engine/orders/OrderStatus.php
declare(strict_types=1);

namespace Engine\Orders;

final class OrderStatus
{
    /** Klucze w bazie */
    public const ALL = [
        'nowe',
        'oczekuje_na_dane',
        'oczekuje_na_płatność',
        'otwarta_paczka:add_products',
        'otwarta_paczka:payment_only',
        'w_realizacji',
        'gotowe_do_wysyłki',
        'do_wyslania',
        'wysłane',
        'zrealizowane',
        'anulowane',
        'zarchiwizowane',
    ];

    /** Aliasowanie — bez ogonków / alternatywne zapisy */
    public const ALIASES = [
        'oczekuje_na_platnosc'        => 'oczekuje_na_płatność',
        'gotowe_do_wysylki'           => 'gotowe_do_wysyłki',
        'wyslane'                     => 'wysłane',
        'otwarta_paczka'              => 'otwarta_paczka:add_products',
        'otwarta_paczka_add_products' => 'otwarta_paczka:add_products',
        'otwarta_paczka_payment_only' => 'otwarta_paczka:payment_only',
        'do_wysłania'                 => 'do_wyslania',
        // (opcjonalnie) angielskie aliasy jeśli kiedyś trafią z API:
        'new'                         => 'nowe',
        'awaiting_payment'            => 'oczekuje_na_płatność',
        'open_package:add_products'   => 'otwarta_paczka:add_products',
        'open_package:payment_only'   => 'otwarta_paczka:payment_only',
        'ready_for_shipping'          => 'gotowe_do_wysyłki',
        'shipped'                     => 'wysłane',
        'completed'                   => 'zrealizowane',
        'cancelled'                   => 'anulowane',
        'archived'                    => 'zarchiwizowane',
    ];

    /** Etykiety do UI */
    public const LABELS = [
        'nowe'                        => 'Nowe',
        'oczekuje_na_dane'            => 'Oczekuje na dane',
        'oczekuje_na_płatność'        => 'Czeka na płatność',
        'otwarta_paczka:add_products' => 'Otwarta paczka: dodawanie',
        'otwarta_paczka:payment_only' => 'Otwarta paczka: płatność',
        'w_realizacji'                => 'W realizacji',
        'gotowe_do_wysyłki'           => 'Gotowe do wysyłki',
        'do_wyslania'                 => 'Do wysłania',
        'wysłane'                     => 'Wysłane',
        'zrealizowane'                => 'Zrealizowane',
        'anulowane'                   => 'Anulowane',
        'zarchiwizowane'              => 'Zarchiwizowane',
    ];

    /** Klasy badge (opcjonalnie spójne kolory) */
    public const BADGES = [
        'nowe'                        => 'bg-gray-100 text-gray-800',
        'oczekuje_na_dane'            => 'bg-stone-100 text-stone-800',
        'oczekuje_na_płatność'        => 'bg-amber-100 text-amber-800',
        'otwarta_paczka:add_products' => 'bg-amber-50 text-amber-700',
        'otwarta_paczka:payment_only' => 'bg-amber-50 text-amber-700',
        'w_realizacji'                => 'bg-indigo-100 text-indigo-800',
        'gotowe_do_wysyłki'           => 'bg-blue-100 text-blue-800',
        'do_wyslania'                 => 'bg-blue-100 text-blue-800',
        'wysłane'                     => 'bg-indigo-100 text-indigo-800',
        'zrealizowane'                => 'bg-emerald-100 text-emerald-800',
        'anulowane'                   => 'bg-stone-100 text-stone-700',
        'zarchiwizowane'              => 'bg-stone-100 text-stone-700',
    ];

    public static function normalize(string $s): string
    {
        $k = mb_strtolower(trim($s));
        return self::ALIASES[$k] ?? $k;
    }

    public static function isAllowed(string $s): bool
    {
        return in_array($s, self::ALL, true);
    }

    public static function label(string $s): string
    {
        return self::LABELS[$s] ?? $s;
    }

    public static function badge(string $s): string
    {
        return self::BADGES[$s] ?? 'bg-stone-100 text-stone-700';
    }
    public static function label(string $status): string {
    return match ($status) {
        'otwarta_paczka:add_products' => 'Dodawanie produktów',
        'otwarta_paczka:payment_only' => 'Czeka na checkout',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

public static function isOpenPackage(string $status): bool {
    return str_starts_with($status, 'otwarta_paczka');
}
public static function label(string $status): string {
    return match ($status) {
        'otwarta_paczka:add_products' => 'Dodawanie produktów',
        'otwarta_paczka:payment_only' => 'Czeka na checkout',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

public static function isOpenPackage(string $status): bool {
    return str_starts_with($status, 'otwarta_paczka');
}

}
