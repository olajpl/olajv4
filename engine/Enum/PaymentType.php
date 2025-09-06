<?php
declare(strict_types=1);

namespace Engine\Enums;

enum PaymentType: string
{
    case MANUAL = 'manual';  // np. przelew tradycyjny, pobranie, BLIK na telefon (bez bramki)
    case ONLINE = 'online';  // np. Przelewy24, PayU, Stripe, tpay itd.
}
