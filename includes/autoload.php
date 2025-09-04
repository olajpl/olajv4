<?php

spl_autoload_register(function ($class) {
    $prefixes = [
        'Engine\\' => __DIR__ . '/../engine/',
        'CentralMessaging\\' => __DIR__ . '/../engine/centralMessaging/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = $baseDir . $rel . '.php';
            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
});
