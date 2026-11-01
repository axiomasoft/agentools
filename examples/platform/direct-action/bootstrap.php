<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/packages/agentools-postgres/vendor/autoload.php';
// Current path-package sources take precedence over installed Composer mirrors.
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['AgenTools\\' => '/php/src/', 'AgenToolsPlatform\\Core\\' => '/packages/agentools-core/src/',
        'AgenToolsPlatform\\Postgres\\' => '/packages/agentools-postgres/src/',
        'AgenToolsPlatform\\Examples\\DirectAction\\' => '/examples/platform/direct-action/src/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require_once $file; }
        }
    }
}, true, true);
