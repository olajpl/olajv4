<?php
declare(strict_types=1);

namespace Engine\Enums;

enum PaymentMethodCode: string
{
    // MANUAL
    case BANK_TRANSFER   = 'bank_transfer';
    case CASH_ON_DELIVERY= 'cod';
    case BLIK_PHONE      = 'blik_phone';

    // ONLINE (gateway)
    case P24             = 'p24';
    case PAYU            = 'payu';
    case STRIPE_CARD     = 'stripe_card';
    case TPAY            = 'tpay';
    // ...rozszerzysz kiedy zechcesz
}
