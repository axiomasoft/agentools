<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';
$root=dirname(__DIR__,3);
require_once $root.'/packages/agentools-core/tests/Common/Harness.php';
require_once $root.'/packages/agentools-core/tests/Common/JcsAdapter.php';
require_once $root.'/packages/agentools-postgres/tests/IsolatedDatabase.php';
spl_autoload_register(static function(string $class) use($root):void {
    $prefix='AgenToolsPlatform\\Examples\\Chatom\\';
    if(str_starts_with($class,$prefix)) {
        $file=$root.'/examples/platform/chatom/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($file)) { require_once $file; }
    }
});
