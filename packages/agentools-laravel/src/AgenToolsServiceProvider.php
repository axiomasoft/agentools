<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel;

use AgenToolsPlatform\Laravel\Managed\{Executor, HostBindings, LifecycleHealth, Scope};
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

/** Explicit registration only. No discovery, routes, plugins or mutable global Facade cache. */
final class AgenToolsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LifecycleHealth::class, static fn (): LifecycleHealth => new LifecycleHealth());
        // Eager, user-free health is shared by Octane sandboxes cloned from this worker.
        $this->app->make(LifecycleHealth::class);
        $this->app->scoped(Scope::class, static fn (\Illuminate\Container\Container $app): Scope => new Scope($app->make(LifecycleHealth::class)));
        $this->app->scoped(Executor::class, static fn (\Illuminate\Container\Container $app): Executor => new Executor($app, $app->make(Scope::class)));
        $this->app->scoped(HostBindings::class, static function (): never {
            throw new \LogicException('Register trusted scoped HostBindings in the host provider');
        });
        foreach (HostBindings::PORTS as $port => $property) {
            $this->app->scoped($port, static fn (\Illuminate\Container\Container $app): object => $app->make(HostBindings::class)->{$property});
        }
        $this->app->afterResolving(HostBindings::class, static function (HostBindings $ports, \Illuminate\Container\Container $app): void {
            try {
                $ports->assertEnvironment($app);
                $app->make(Scope::class)->attach($ports);
            } catch (\Throwable $error) {
                try { $ports->reset(); }
                catch (Managed\LifecycleFailure) { $app->make(LifecycleHealth::class)->poison(); throw new Managed\LifecycleFailure(); }
                finally { Executor::forget($app); }
                throw $error;
            }
        });
    }

    public function boot(Dispatcher $events): void
    {
        foreach (['Illuminate\\Queue\\Events\\JobProcessing', 'Illuminate\\Queue\\Events\\JobProcessed',
            'Illuminate\\Queue\\Events\\JobExceptionOccurred', 'Illuminate\\Queue\\Events\\JobFailed',
            'Illuminate\\Queue\\Events\\JobAttempted', 'Illuminate\\Foundation\\Http\\Events\\RequestHandled',
            'Illuminate\\Foundation\\Events\\Terminating', 'Laravel\\Octane\\Events\\RequestReceived',
            'Laravel\\Octane\\Events\\RequestTerminated', 'Laravel\\Octane\\Events\\TaskReceived',
            'Laravel\\Octane\\Events\\TaskTerminated', 'Laravel\\Octane\\Events\\TickReceived',
            'Laravel\\Octane\\Events\\TickTerminated', 'Laravel\\Octane\\Events\\WorkerErrorOccurred',
            'Laravel\\Octane\\Contracts\\OperationTerminated'] as $event) {
            $events->listen($event, function (mixed $event = null): void {
                // Never instantiate user services merely to clean an unused scope.
                $app = $this->app;
                if (is_object($event) && str_starts_with($event::class, 'Laravel\\Octane\\Events\\')
                    && isset($event->sandbox) && $event->sandbox instanceof \Illuminate\Container\Container) {
                    $app = $event->sandbox;
                }
                if (!$app instanceof \Illuminate\Container\Container) { throw new \LogicException('Laravel container required'); }
                try {
                    $app->make(Scope::class)->retire();
                } finally {
                    Executor::forget($app);
                    $app->forgetInstance(Executor::class);
                    $app->forgetInstance(Scope::class);
                }
            });
        }
        $this->commands([Console\RunInput::class]);
    }
}
