<?php

declare(strict_types=1);

[$script,$package,$mode] = $argv;
require $package.'/vendor/autoload.php';
$app = new \Illuminate\Foundation\Application($package);
$app->instance('env','production');
$events = new \Illuminate\Events\Dispatcher($app); $app->instance('events',$events);
$router = new \Illuminate\Routing\Router($events,$app); $app->instance('router',$router);
$provider = new \AgenToolsPlatform\Laravel\AgenToolsServiceProvider($app);
$provider->register(); $provider->boot($events);
$scheduler = class_exists(\AgenToolsPlatform\Scheduler\Store::class);
$observations = ['provider'=>true,'default_routes'=>count($router->getRoutes()->getRoutes()),
    'default_host_bindings'=>$app->resolved(\AgenToolsPlatform\Laravel\Managed\HostBindings::class),
    'testing_autoload'=>class_exists(\PHPUnit\Framework\TestCase::class) || class_exists('PHPStan\\Analyser\\Analyser')
        || class_exists(\AgenToolsPlatform\Testing\CaseResult::class),
    'scheduler'=>$scheduler, 'core_port'=>interface_exists(\AgenToolsPlatform\Core\Scheduling\SchedulePort::class),
    'filament'=>class_exists('Filament\\Panel')];
if ($observations['default_routes'] !== 0 || $observations['default_host_bindings'] || $observations['testing_autoload']
    || !$observations['core_port'] || $observations['filament'] || ($mode === 'headless' && $scheduler)
    || ($mode === 'adapter' && (!$scheduler || !is_file($package.'/src/schema.sql')
        || !in_array(\AgenToolsPlatform\Core\Scheduling\SchedulePort::class,
            (new ReflectionClass(\AgenToolsPlatform\Scheduler\Store::class))->getInterfaceNames(),true)))) {
    throw new RuntimeException('Scheduler no-dev boundary failed');
}
echo json_encode($observations,JSON_THROW_ON_ERROR)."\n";
