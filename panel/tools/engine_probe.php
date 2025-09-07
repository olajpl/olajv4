<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

echo "Probe: Engine\\Orders\\ProductEngine\n";

$exists = class_exists(\Engine\Orders\ProductEngine::class);
echo "class_exists: ".($exists?'YES':'NO')."\n";

if ($exists) {
    echo "has method listProducts: ".(method_exists(\Engine\Orders\ProductEngine::class, 'listProducts')?'YES':'NO')."\n";
    try {
        // suchar: mały dry-run (dopasuj parametry do Twojej sygnatury)
        $res = \Engine\Orders\ProductEngine::listProducts($pdo, [
            'owner_id' => (int)($_SESSION['user']['owner_id'] ?? 1),
            'limit'    => 1,
        ]);
        echo "call ok: YES (".(is_array($res)?'array':'?').")\n";
    } catch (Throwable $e) {
        echo "call ok: NO\n";
        echo "EX: ".$e->getMessage()."\n";
        echo "AT: ".$e->getFile().":".$e->getLine()."\n";
    }
} else {
    echo "Autoload paths that were tried are in bootstrap autoloader logic.\n";
}
