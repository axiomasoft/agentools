<?php

declare(strict_types=1);

[$script, $package, $mode] = $argv;
require $package.'/vendor/autoload.php';

$app = new \Illuminate\Foundation\Application($package);
$app->instance('env', 'production');
$events = new \Illuminate\Events\Dispatcher($app); $app->instance('events', $events);
$router = new \Illuminate\Routing\Router($events, $app); $app->instance('router', $router);
$provider = new \AgenToolsPlatform\Laravel\AgenToolsServiceProvider($app);
$provider->register(); $provider->boot($events);
$filament = class_exists(\Filament\Panel::class);
if ($mode === 'adapter') {
    $admin = new \AgenToolsPlatform\Filament\InspectorServiceProvider($app);
    $admin->register(); $admin->boot($events);
    if (!class_exists(\AgenToolsPlatform\Filament\Pages\Inspect::class) || !$filament
        || !class_exists(\AgenToolsPlatform\Filament\Pages\Profiles::class)
        || !class_exists(\AgenToolsPlatform\Postgres\Profiles\BundleRepository::class)
        || !class_exists(\AgenToolsPlatform\Postgres\Profiles\SessionRepository::class)) { throw new \RuntimeException('Adapter missing Filament/profile services'); }
}
$observations = ['provider' => true, 'default_routes' => count($router->getRoutes()->getRoutes()),
    'default_host_bindings' => $app->resolved(\AgenToolsPlatform\Laravel\Managed\HostBindings::class),
    'testing_autoload' => class_exists(\PHPUnit\Framework\TestCase::class) || class_exists('PHPStan\\Analyser\\Analyser')
        || class_exists(\AgenToolsPlatform\Testing\CaseResult::class), 'filament' => $filament,
    'profile_admin' => $mode === 'adapter' && $app->bound(\AgenToolsPlatform\Filament\Profiles\Admin::class)];
if ($observations['default_routes'] !== 0 || $observations['default_host_bindings'] || $observations['testing_autoload']
    || ($mode === 'headless' && $filament)) { throw new \RuntimeException('No-dev boundary failed'); }
echo json_encode($observations, JSON_THROW_ON_ERROR)."\n";
