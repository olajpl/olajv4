<?php
function getPaymentSettings($owner_id) {
    return [
        'enabled' => get_setting($owner_id, 'payments_enabled') === 'true',
        'cod_requires_approval' => get_setting($owner_id, 'payment_cod_requires_approval') === 'true',
        'available_methods' => json_decode(get_setting($owner_id, 'payment_methods_available'), true),
        'gateway' => get_setting($owner_id, 'payment_gateway'),
    ];
}
