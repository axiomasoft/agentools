<?php

declare(strict_types=1);

$loader = require __DIR__.'/vendor/autoload.php';
foreach (glob(dirname(__DIR__,2).'/src/Ingress/*.php') as $source) {
    $installed = __DIR__.'/vendor/agentools/platform-loqum/src/Ingress/'.basename($source);
    if (!is_file($installed) || hash_file('sha256',$source) !== hash_file('sha256',$installed)) {
        throw new RuntimeException('No-dev installation is missing/stale; finish Composer setup');
    }
}
foreach (['AgenToolsPlatform\\Loqum\\Ingress\\Boundary','AgenToolsPlatform\\Loqum\\Ingress\\Owner',
    'AgenToolsPlatform\\Core\\Security\\TrustedInput'] as $class) {
    if (!class_exists($class) && !interface_exists($class)) { throw new RuntimeException('Missing production type '.$class); }
}
foreach (['AgenToolsPlatform\\Loqum\\Tests\\Fixture','AgenToolsPlatform\\Testing\\FakeTransport',
    'PHPStan\\Command\\AnalyseCommand','PHPUnit\\Framework\\TestCase'] as $class) {
    if (class_exists($class)) { throw new RuntimeException('Development type leaked '.$class); }
}
foreach (array_keys($loader->getPrefixesPsr4()) as $prefix) {
    if (preg_match('/Tests|Testing|TestKit|Reference|PHPUnit|PHPStan/', $prefix)) {
        throw new RuntimeException('Development autoload leaked');
    }
}
echo json_encode(['mode'=>'contract-scripted','tier'=>'contract-scripted',
    'cases'=>[['id'=>'voice.production-no-dev','status'=>'passed']], 'count'=>1,'failures'=>0,'skips'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
