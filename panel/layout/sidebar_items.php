<?php
$items = [
    ['link' => '/admin/index.php', 'label' => 'Dashboard', 'icon' => 'home'],
    ['link' => '/admin/clients/', 'label' => 'Klienci', 'icon' => 'users'],
    ['link' => '/admin/messages/', 'label' => 'Wiadomości', 'icon' => 'message-circle'],
    ['link' => '/admin/live/', 'label' => 'Transmisje', 'icon' => 'tv'],
    ['link' => '/admin/raffles/', 'label' => 'Losowania', 'icon' => 'dice-5'],
    ['link' => '/admin/orders/', 'label' => 'Zamówienia', 'icon' => 'file-text'],
    [
        'label' => 'Produkty',
        'icon' => 'package',
        'children' => [
            ['link' => '/admin/products/', 'label' => 'Lista'],
            ['link' => '/admin/products/tags.php', 'label' => 'Tagi Produktów']
            ['link' => '/admin/purchases/center.php', 'label' => 'Dostawcy', 'icon' => '🏢', 'type' => 'suppliers'],
        ]
    ],
    ['link' => '/admin/purchases/center.php', 'label' => 'Dostawcy', 'icon' => 'truck'],
    ['link' => '/admin/settings/', 'label' => 'Ustawienia', 'icon' => 'settings'],
    ['link' => '/admin/cw/', 'label' => 'Centralny Wysyłacz', 'icon' => 'send'],
    ['link' => '/admin/logs/', 'label' => 'Logger', 'icon' => 'terminal'],
];
