<?php

declare(strict_types=1);
require_once dirname(__DIR__, 3).'/packages/agentools-postgres/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $root = dirname(__DIR__, 3);
    foreach (['AgenToolsPlatform\\Core\\' => $root.'/packages/agentools-core/src/',
        'AgenToolsPlatform\\Postgres\\' => $root.'/packages/agentools-postgres/src/',
        'AgenToolsPlatform\\Laravel\\' => $root.'/packages/agentools-laravel/src/',
        'AgenToolsPlatform\\Examples\\SupportHost\\' => __DIR__.'/src/'] as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $file = $base.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) { require_once $file; }
        }
    }
}, prepend: true);

require_once dirname(__DIR__).'/read-journey/bootstrap.php';
require_once dirname(__DIR__).'/confirmed-write/bootstrap.php';
