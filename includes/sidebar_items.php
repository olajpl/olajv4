<?php
return [
    ['link' => '/admin/index.php', 'label' => 'Dashboard', 'icon' => '🏠', 'type' => 'home'],
    ['link' => '/admin/clients/', 'label' => 'Klienci', 'icon' => '👥', 'type' => 'clients'],
    ['link' => '/admin/messages/', 'label' => 'Wiadomości', 'icon' => '💬', 'type' => 'messages'],
    ['link' => '/admin/live/', 'label' => 'Transmisje', 'icon' => '📺', 'type' => 'live'],
    ['link' => '/admin/raffles/', 'label' => 'Losowania', 'icon' => '🎲', 'type' => 'raffles'],
    ['link' => '/admin/orders/', 'label' => 'Zamówienia', 'icon' => '🧾', 'type' => 'orders'],
    [
        'label' => 'Produkty',
        'icon' => '📦',
        'type' => 'products',
        'children' => [
            ['link' => '/admin/products/', 'label' => 'Lista'],
            ['link' => '/admin/products/tags.php', 'label' => 'Tagi Produktów'],
            ['link' => '/admin/purchases/center.php', 'label' => 'Dostawcy']
        ]
    ],
    ['link' => '/admin/purchases/center.php', 'label' => 'Dostawcy', 'icon' => '🏢', 'type' => 'suppliers'],
    ['link' => '/admin/settings/', 'label' => 'Ustawienia', 'icon' => '⚙️', 'type' => 'settings'],
    ['link' => '/admin/cw/', 'label' => 'Centralny Wysyłacz', 'icon' => '🚚', 'type' => 'cw'],
    ['link' => '/admin/logs/', 'label' => 'Logger', 'icon' => '🧱', 'type' => 'logs'],
];
