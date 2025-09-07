<?php
// tools/which_productengine.php
declare(strict_types=1);
require_once __DIR__.'/../../../bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
foreach (['Engine\\Product\\ProductEngine','Engine\\Orders\\ProductEngine'] as $fqcn) {
    echo "Class: $fqcn\n";
    if (class_exists($fqcn)) {
        $ref = new ReflectionClass($fqcn);
        echo "  file: ".$ref->getFileName()."\n";
        echo "  methods: ".count($ref->getMethods())."\n";
    } else {
        echo "  NOT FOUND\n";
    }
}
