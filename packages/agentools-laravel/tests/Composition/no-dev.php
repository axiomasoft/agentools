<?php

declare(strict_types=1);

require $argv[1].'/vendor/autoload.php';

use AgenToolsPlatform\Laravel\AgenToolsServiceProvider;
use AgenToolsPlatform\Laravel\Managed\{Executor, HostBindings};
use Illuminate\Foundation\Application;
use Illuminate\Events\Dispatcher;

$app = new Application($argv[1]);
$events = new Dispatcher($app);
$provider = new AgenToolsServiceProvider($app);
$provider->register(); $provider->boot($events);
$app->make(Executor::class);
if (count($app->make(\Illuminate\Routing\Router::class)->getRoutes()->getRoutes()) !== 0) {
    throw new RuntimeException('No-dev provider/route isolation failed');
}
try { $app->make(HostBindings::class); throw new RuntimeException('Default authority granted'); }
catch (Throwable $error) {
    if ($error->getMessage() !== 'Register trusted scoped HostBindings in the host provider') { throw $error; }
}
foreach (['AgenToolsPlatform\\Testing\\ScriptedTransport','AgenToolsPlatform\\Laravel\\Tests\\Composition\\Fixture','PHPUnit\\Framework\\TestCase'] as $class) {
    if (class_exists($class)) { throw new RuntimeException('Testing dependency in no-dev closure'); }
}
echo json_encode(['provider'=>true,'default_routes'=>0,'default_host_bindings'=>false,'testing_autoload'=>false],JSON_THROW_ON_ERROR);
