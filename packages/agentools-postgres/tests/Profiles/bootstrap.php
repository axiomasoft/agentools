<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
// Path dependencies are installed copies. Test the current tree without rewriting any vendor owner.
spl_autoload_register(static function (string $class): void {
    foreach (['AgenToolsPlatform\\Core\\' => dirname(__DIR__, 3) . '/agentools-core/src/',
        'AgenToolsPlatform\\Postgres\\' => dirname(__DIR__, 2) . '/src/'] as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require_once $file; }
        }
    }
}, prepend: true);
// Keep this runner's PHPUnit dependency owner; Common/bootstrap would load the SDK's dev runner too.
require_once dirname(__DIR__, 3) . '/agentools-core/tests/Common/JcsAdapter.php';
require_once dirname(__DIR__, 3) . '/agentools-core/tests/Common/TestHost.php';
require_once dirname(__DIR__, 3) . '/agentools-core/tests/Profiles/Fixture.php';
require_once __DIR__ . '/Fixture.php';
require_once __DIR__ . '/DatabaseCase.php';
require_once __DIR__ . '/Evidence.php';
