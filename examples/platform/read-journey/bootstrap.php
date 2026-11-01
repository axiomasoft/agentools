<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
if (!extension_loaded('sodium')) { throw new RuntimeException('ext-sodium is required for the synthetic encrypted host vault'); }
require_once $root . '/packages/agentools-postgres/vendor/autoload.php';
// Use current local path-package source; installed mirrors are generated dependencies.
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['AgenTools\\' => '/php/src/', 'AgenToolsPlatform\\Core\\' => '/packages/agentools-core/src/',
        'AgenToolsPlatform\\Postgres\\' => '/packages/agentools-postgres/src/',
        'AgenToolsPlatform\\Examples\\ReadJourney\\' => '/examples/platform/read-journey/src/',
        'AgenToolsPlatform\\Testing\\' => '/packages/agentools-testing/src/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require_once $file; }
        }
    }
}, true, true);
