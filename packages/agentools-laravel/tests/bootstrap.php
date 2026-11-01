<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__,3).'/examples/platform/confirmed-write/bootstrap.php';
spl_autoload_register(static function (string $class): void {
    $prefix='AgenToolsPlatform\\Laravel\\';
    if (str_starts_with($class,$prefix)) {
        $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if (is_file($path)) { require_once $path; }
    }
},true,true);
