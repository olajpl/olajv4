<?php
// engine/Enum/NotificationEvent.php — Olaj.pl V4 ENUM: Notification Events
declare(strict_types=1);

namespace Engine\Enum;

enum NotificationEvent: string
{
    // 🔶 Braki danych do wysyłki
    case ORDER_MISSING_DATA = 'order_missing_data';

    // 🔴 Płatność nieopłacona po czasie
    case ORDER_PAYMENT_OVERDUE = 'order_payment_overdue';

    // 🟢 Inne (przykładowe – możesz dodać później)
    case ORDER_CONFIRMED = 'order_confirmed';
    case ORDER_SHIPPED = 'order_shipped';
    case CLIENT_WELCOME = 'client_welcome';

    public function label(): string
    {
        return match ($this) {
            self::ORDER_MISSING_DATA     => 'Brak danych do wysyłki',
            self::ORDER_PAYMENT_OVERDUE  => 'Nieopłacone zamówienie',
            self::ORDER_CONFIRMED        => 'Zamówienie potwierdzone',
            self::ORDER_SHIPPED          => 'Zamówienie wysłane',
            self::CLIENT_WELCOME         => 'Powitanie klienta',
        };
    }
}
