<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/agentools-core/tests/Common/bootstrap.php';
require_once dirname(__DIR__,3).'/agentools-core/tests/Usage/Fixture.php';
require_once dirname(__DIR__,3).'/agentools-core/tests/Usage/Owner.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    foreach (['AgenToolsPlatform\\Core\\'=>'agentools-core','AgenToolsPlatform\\ModelAdapter\\'=>'agentools-model-adapter',
        'AgenToolsPlatform\\Postgres\\'=>'agentools-postgres'] as $prefix=>$package) {
        if (str_starts_with($class,$prefix)) {
            $relative=str_replace('\\','/',substr($class,strlen($prefix)));
            $path=dirname(__DIR__,3).'/'.$package.'/src/'.$relative.'.php';
            if (str_starts_with($relative,'Tests/')) { $path=dirname(__DIR__,3).'/'.$package.'/tests/'.substr($relative,6).'.php'; }
            if (is_file($path)) { require_once $path; }
        }
    }
},prepend:true);
foreach (['MemoryStore','Fixture','Host','Provider','Sink','Proof','BudgetOwner'] as $name) { require_once __DIR__.'/'.$name.'.php'; }
