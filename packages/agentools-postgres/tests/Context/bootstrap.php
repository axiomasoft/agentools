<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';
spl_autoload_register(static function(string $class): void {
    foreach (['AgenToolsPlatform\\Core\\'=>dirname(__DIR__,3).'/agentools-core/src/',
        'AgenToolsPlatform\\Postgres\\'=>dirname(__DIR__,2).'/src/'] as $prefix=>$base) {
        if (str_starts_with($class,$prefix)) {
            $file=$base.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
            if (is_file($file)) { require_once $file; }
        }
    }
},prepend:true);
foreach (['Harness','JcsAdapter','TestHost'] as $name) { require_once dirname(__DIR__,3).'/agentools-core/tests/Common/'.$name.'.php'; }
require_once dirname(__DIR__,3).'/agentools-core/tests/Context/Fixture.php';
