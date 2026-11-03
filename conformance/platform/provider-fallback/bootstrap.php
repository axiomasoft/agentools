<?php
declare(strict_types=1);
$root=dirname(__DIR__,3);
require_once $root.'/packages/agentools-core/tests/Common/bootstrap.php';
require_once $root.'/packages/agentools-model-adapter/vendor/autoload.php';
require_once $root.'/packages/agentools-testing/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['AgenToolsPlatform\\Core\\'=>'agentools-core','AgenToolsPlatform\\Testing\\'=>'agentools-testing','AgenToolsPlatform\\ModelAdapter\\'=>'agentools-model-adapter'] as $prefix=>$package) {
        if (str_starts_with($class,$prefix)) {
            $p=$root.'/packages/'.$package.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php'; if (is_file($p)) { require_once $p; }
        }
    }
},prepend:true);
require_once $root.'/packages/agentools-core/tests/Models/Routing/Fixture.php';
require_once $root.'/packages/agentools-core/tests/Usage/Fixture.php';
require_once $root.'/packages/agentools-model-adapter/tests/Fixtures.php';
foreach (['Fixture','Owner','Proof'] as $name) { require_once __DIR__.'/'.$name.'.php'; }
