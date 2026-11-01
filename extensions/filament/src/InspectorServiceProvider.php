<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament;

use AgenToolsPlatform\Laravel\Managed\{HostBindings, LifecycleHealth};
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

/** Explicit, route-free registration. The headless Laravel package never requires this package. */
final class InspectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Scope::class, static fn (Container $app): Scope => new Scope($app->make(LifecycleHealth::class)));
        foreach ([Access::class, Diagnostics::class] as $type) {
            $this->app->afterResolving($type, static function (Access|Diagnostics $port, Container $app): void {
                $app->make(Scope::class)->attach($port);
            });
        }
        $this->app->scoped(Access::class, static function (): never { throw new InspectionFailure('host_access_required'); });
        $this->app->scoped(Bindings::class, static fn (Container $app): Bindings => new Bindings(
            $app->make(HostBindings::class), $app->make(Access::class), $app->bound(Diagnostics::class) ? $app->make(Diagnostics::class) : null));
        $this->app->afterResolving(Bindings::class, static function (Bindings $bindings, Container $app): void {
            $scope = $app->make(Scope::class);
            $scope->attach($bindings->access);
            if ($bindings->diagnostics !== null) { $scope->attach($bindings->diagnostics); }
            $bindings->assertEnvironment($app);
        });
        $this->app->scoped(Inspector::class, static function (Container $app): Inspector {
            $bindings = $app->make(Bindings::class);
            $bindings->assertEnvironment($app);
            $inspector = new Inspector($bindings, $app->make(LifecycleHealth::class));
            $app->make(Scope::class)->inspector($inspector);
            return $inspector;
        });
        $this->app->afterResolving(Profiles\Access::class, static function (Profiles\Access $access, Container $app): void {
            $app->make(Scope::class)->attach($access);
        });
        $this->app->scoped(Profiles\Bindings::class, static function (): never { throw new InspectionFailure('host_profiles_required'); });
        $this->app->afterResolving(Profiles\Bindings::class, static function (Profiles\Bindings $bindings, Container $app): void {
            $app->make(Scope::class)->attach($bindings->access);
            $app->make(HostBindings::class)->assertEnvironment($app);
            if (!$app->bound('agentools.testing') || $app->make('agentools.testing') !== true) { $bindings->assertProduction(); }
        });
        $this->app->scoped(Profiles\Admin::class, static function (Container $app): Profiles\Admin {
            $admin = new Profiles\Admin($app->make(Profiles\Bindings::class), $app->make(HostBindings::class), $app->make(LifecycleHealth::class));
            $app->make(Scope::class)->profiles($admin);
            return $admin;
        });
    }

    public function boot(Dispatcher $events): void
    {
        $this->loadViewsFrom(dirname(__DIR__).'/resources/views', 'agentools-inspector');
        foreach (['Illuminate\\Queue\\Events\\JobProcessing', 'Illuminate\\Queue\\Events\\JobProcessed',
            'Illuminate\\Queue\\Events\\JobExceptionOccurred', 'Illuminate\\Queue\\Events\\JobFailed',
            'Illuminate\\Queue\\Events\\JobAttempted', 'Illuminate\\Foundation\\Http\\Events\\RequestHandled',
            'Illuminate\\Foundation\\Events\\Terminating', 'Laravel\\Octane\\Events\\RequestReceived',
            'Laravel\\Octane\\Events\\RequestTerminated', 'Laravel\\Octane\\Events\\TaskReceived',
            'Laravel\\Octane\\Events\\TaskTerminated', 'Laravel\\Octane\\Events\\TickReceived',
            'Laravel\\Octane\\Events\\TickTerminated', 'Laravel\\Octane\\Events\\WorkerErrorOccurred',
            'Laravel\\Octane\\Contracts\\OperationTerminated'] as $event) {
            $events->listen($event, function (mixed $event = null): void {
                $app = $this->app;
                if (is_object($event) && str_starts_with($event::class, 'Laravel\\Octane\\Events\\')
                    && isset($event->sandbox) && $event->sandbox instanceof Container) { $app = $event->sandbox; }
                if (!$app instanceof Container) { throw new \LogicException('Laravel container required'); }
                try {
                    if ($app->resolved(Scope::class)) { $app->make(Scope::class)->retire(); }
                } finally {
                    foreach ([Scope::class, Inspector::class, Bindings::class, Access::class, Diagnostics::class,
                        Profiles\Admin::class, Profiles\Bindings::class, Profiles\Access::class] as $type) { $app->forgetInstance($type); }
                }
            });
        }
    }
}
