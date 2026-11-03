<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Console;

use Illuminate\Support\ServiceProvider;

/** Separate opt-in: host explicitly binds scoped Facade, Host and RequestSource. */
final class OperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([Doctor::class,InspectRun::class,Recover::class,Reconcile::class,Relay::class,Prune::class]);
    }
}
