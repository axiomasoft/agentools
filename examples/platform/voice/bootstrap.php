<?php

declare(strict_types=1);

require_once dirname(__DIR__,3).'/extensions/loqum/vendor/autoload.php';
require_once dirname(__DIR__,3).'/packages/agentools-postgres/tests/IsolatedDatabase.php';
require_once __DIR__.'/src/RuntimePorts.php';
spl_autoload_register(static function(string $class):void {
    $prefix='AgenToolsPlatform\\Examples\\Voice\\';
    if (str_starts_with($class,$prefix)) {
        $file=__DIR__.'/src/'.substr($class,strlen($prefix)).'.php';
        if (is_file($file)) { require_once $file; }
    }
});
