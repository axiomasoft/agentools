<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
// Local path packages are Composer mirrors. Exercise the current candidate source without
// rewriting the generated dependency baseline or changing the installed dated SDK.
spl_autoload_register(static function (string $class): void {
    foreach (['AgenToolsPlatform\\Core\\' => '/agentools-core/src/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = dirname(__DIR__, 3) . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require_once $file; }
        }
    }
}, true, true);
