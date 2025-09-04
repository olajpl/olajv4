<?php
// /engine/autoload.php — PSR-4 autoloader dla Olaj.pl V4 (Linux-safe)
declare(strict_types=1);

/**
 * Zasada: Namespace = PascalCase katalogi, Plik = Klasa.php
 * Przykład: Engine\Orders\OrderEngine -> /engine/Orders/OrderEngine.php
 */

spl_autoload_register(function (string $class): void {
    static $prefixes = [
        'Engine\\'            => __DIR__ . '/',
        // Zachowane prefiksy dla ewentualnych starszych rzeczy:
        'Olaj\\'              => __DIR__ . '/',             // np. Olaj\CW\...
        'CentralMessaging\\'  => __DIR__ . '/CentralMessaging/', // legacy alias
    ];

    $loaded = false;
    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        // względna część (po prefiksie)
        $relative = substr($class, $len);

        // Zamiana separatorów namespace -> katalogi
        $relativePath = str_replace('\\', '/', $relative);

        // Budujemy docelową ścieżkę (PascalCase katalogi zgodne z namespace)
        $file = $baseDir . $relativePath . '.php';

        if (is_file($file)) {
            require_once $file;
            $loaded = true;
            break;
        }
    }

    if (!$loaded) {
        // fallback: log do systemowego logera jeśli jest dostępny
        if (function_exists('logg')) {
            try {
                logg('error', 'autoload', 'Class not found', [
                    'class' => $class
                ], [
                    'context' => 'autoload',
                    'source'  => 'engine/autoload.php'
                ]);
            } catch (\Throwable $e) {
                // cicho, nie chcemy zapętlić
            }
        }
        // Opcjonalnie: można odkomentować, żeby na dev waliło wyjątkiem:
        // throw new \RuntimeException("Autoload failed for: {$class}");
    }
});

// ─────────────────────────────────────────────────────────────
// Alias'y zgodności (jeśli gdzieś zostały stare nazwy klas)
// ─────────────────────────────────────────────────────────────
//
// Przykład: kiedyś było Engine\Payments\PaymentEngine, teraz Engine\Orders\PaymentEngine.
// Dodajemy alias, żeby stare wywołania nie wybuchły.

$compatAliases = [
    // „Payments\PaymentEngine” -> „Orders\PaymentEngine”
    'Engine\\Payments\\PaymentEngine'        => 'Engine\\Orders\\PaymentEngine',
    // „CentralMessaging\Cw” pod inną przestrzenią (gdyby się trafiło)
    'CentralMessaging\\Cw'                   => 'Engine\\CentralMessaging\\Cw',
    'CentralMessaging\\CwHelper'             => 'Engine\\CentralMessaging\\CwHelper',
    'CentralMessaging\\CwTemplateResolver'   => 'Engine\\CentralMessaging\\CwTemplateResolver',
];

foreach ($compatAliases as $old => $new) {
    if (!class_exists($old, false) && class_exists($new, false)) {
        class_alias($new, $old);
    }
}
