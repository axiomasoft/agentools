<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Context/bootstrap.php';
require_once dirname(__DIR__,3).'/agentools-core/tests/Usage/Fixture.php';
require_once dirname(__DIR__,3).'/agentools-core/tests/Usage/Owner.php';
spl_autoload_register(static function (string $class): void {
    $prefix='AgenToolsPlatform\\Laravel\\';
    if (str_starts_with($class,$prefix)) {
        $file=dirname(__DIR__,3).'/agentools-laravel/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if (is_file($file)) { require_once $file; }
    }
},prepend:true);
require_once __DIR__.'/Fixture.php';
require_once __DIR__.'/Owner.php';
require_once __DIR__.'/Meter.php';
