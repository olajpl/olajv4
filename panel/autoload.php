<?php
spl_autoload_register(function ($class) {
    $map = [
        'Olaj\\CW\\' => __DIR__ . '/engine/centralMessaging/',
        'Engine\\'   => __DIR__ . '/engine/',
    ];
    foreach ($map as $prefix => $base) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $rel = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            $f = $base . $rel;
            if (is_file($f)) {
                require $f;
                return;
            }
        }
    }
});
