<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Laravel\Managed\{DeliveryReceipt, Entry, Executor, HostBindings, Input, LifecycleFailure, LifecycleHealth};
use Illuminate\Container\Container;

/** Explicit host job/command adapter; never called automatically by Scanner. */
final readonly class Dispatch
{
    public function __construct(private Store $store, private Container $app) {}
    public function run(string $handle): DeliveryReceipt
    {
        try {
            $context = $this->store->authority->context();
            $this->store->resolve($handle,$context);
            $ports = $this->app->make(HostBindings::class);
            if (!$ports->host instanceof ScheduledHost) { throw new ContractViolation(ErrorCode::PolicyConflict); }
            $ports->host->assertStore($this->store);
            return $this->app->make(Executor::class)->execute(new Input($handle,Entry::Background));
        } finally {
            try { $this->store->authority->reset(); }
            catch (\Throwable) { $this->app->make(LifecycleHealth::class)->poison(); throw new LifecycleFailure(); }
        }
    }
}
