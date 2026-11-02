<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    foreach (['AgenToolsPlatform\\Core\\'=>dirname(__DIR__,3).'/agentools-core/src/',
        'AgenToolsPlatform\\Postgres\\'=>dirname(__DIR__,2).'/src/'] as $prefix=>$base) {
        if (str_starts_with($class,$prefix)) {
            $path = $base.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
            if (is_file($path)) { require_once $path; }
        }
    }
},prepend:true);
require_once dirname(__DIR__,3).'/agentools-core/tests/Common/JcsAdapter.php';
require_once dirname(__DIR__,3).'/agentools-core/tests/Models/Routing/Fixture.php';
