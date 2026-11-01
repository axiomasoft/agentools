<?php

declare(strict_types=1);

require_once dirname(__DIR__,3).'/examples/platform/confirmed-write/bootstrap.php';
spl_autoload_register(static function (string $class): void {
    foreach (['AgenToolsPlatform\\Conformance\\WriteJourney\\'=>__DIR__.'/',
        'AgenToolsPlatform\\Laravel\\'=>dirname(__DIR__,3).'/packages/agentools-laravel/src/'] as $prefix=>$path) {
        if (str_starts_with($class,$prefix)) {
            $file=$path.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
            if (is_file($file)) { require_once $file; }
        }
    }
},true,true);
