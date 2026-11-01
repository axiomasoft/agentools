<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/Composition/bootstrap.php';
spl_autoload_register(static function(string $class):void{
    $prefix='AgenToolsPlatform\\Testing\\';
    if(str_starts_with($class,$prefix)){
        $file=dirname(__DIR__,3).'/agentools-testing/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($file)){require_once $file;}
    }
},true,true);
require_once __DIR__.'/Fixture.php';
require_once __DIR__.'/HookDoubles.php';
