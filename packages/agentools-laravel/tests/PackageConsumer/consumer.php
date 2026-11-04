<?php
declare(strict_types=1);

require $argv[1].'/vendor/autoload.php';

use AgenToolsPlatform\Core\Common\{ContractViolation, TenantId};
use AgenToolsPlatform\Laravel\AgenToolsServiceProvider;
use AgenToolsPlatform\Laravel\Managed\{Entry, Executor, HostBindings, Input};
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;

$tenant = new TenantId('tenant-a');
try { $tenant->assertSame(new TenantId('tenant-b')); throw new RuntimeException('Cross-tenant access accepted'); }
catch (ContractViolation $expected) {}
$input = new Input('inbox:123', Entry::DirectAction);
if ($input->entry !== Entry::DirectAction) { throw new RuntimeException('Direct entry changed'); }
try { new Input(''); throw new RuntimeException('Invalid input accepted'); }
catch (InvalidArgumentException $expected) {}
$app = new Application($argv[1]);
$provider = new AgenToolsServiceProvider($app);
$provider->register(); $provider->boot(new Dispatcher($app));
$app->make(Executor::class);
try { $app->make(HostBindings::class); throw new RuntimeException('Default authority granted'); }
catch (Throwable $error) {
    if ($error->getMessage() !== 'Register trusted scoped HostBindings in the host provider') { throw $error; }
}
if (count($app->make(\Illuminate\Routing\Router::class)->getRoutes()->getRoutes()) !== 0) { throw new RuntimeException('Unexpected route'); }
foreach (['AgenToolsPlatform\\Testing\\ScriptedTransport', 'PHPUnit\\Framework\\TestCase',
          'AgenToolsPlatform\\Chatom\\ChatomServiceProvider', 'Filament\\FilamentServiceProvider'] as $class) {
    if (class_exists($class)) { throw new RuntimeException('Unexpected optional or development class: '.$class); }
}
echo "consumer passed\n";
