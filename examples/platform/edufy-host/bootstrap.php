<?php

declare(strict_types=1);

// Explicit development entry point; never registered by the production service provider.
require_once dirname(__DIR__, 3).'/packages/agentools-core/tests/Common/bootstrap.php';
spl_autoload_register(static function (string $class): void {
    $root = dirname(__DIR__, 3);
    foreach (['AgenToolsPlatform\\Laravel\\' => $root.'/packages/agentools-laravel/src/',
        'AgenToolsPlatform\\Examples\\EdufyHost\\' => __DIR__.'/src/'] as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $file = $base.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) { require_once $file; }
        }
    }
}, prepend: true);
