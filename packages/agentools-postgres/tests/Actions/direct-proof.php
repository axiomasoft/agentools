<?php

declare(strict_types=1);

// A fresh direct consumer must not even load an assistant/model implementation.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'AgenToolsPlatform\\Core\\Runtime\\')
        || str_starts_with($class, 'AgenToolsPlatform\\Core\\Models\\')
        || str_starts_with($class, 'AgenToolsPlatform\\Postgres\\Models\\')) {
        throw new RuntimeException('Direct consumer attempted to load assistant/model code');
    }
});
require dirname(__DIR__,4) . '/examples/platform/direct-action/run.php';
foreach (get_declared_classes() as $class) {
    if (str_starts_with($class, 'AgenToolsPlatform\\Core\\Runtime\\') || str_starts_with($class, 'AgenToolsPlatform\\Core\\Models\\')
        || str_starts_with($class, 'AgenToolsPlatform\\Postgres\\Models\\')) {
        throw new RuntimeException('Direct consumer loaded assistant/model code');
    }
}
