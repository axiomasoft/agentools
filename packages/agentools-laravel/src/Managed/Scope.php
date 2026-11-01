<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Managed;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};

/** Scoped lease. A retained service cannot continue after queue/Octane cleanup. */
final class Scope
{
    private ?HostBindings $bindings = null;
    private bool $running = false;
    private bool $poisoned = false;
    private bool $retired = false;
    private bool $resetting = false;

    public function __construct(private readonly LifecycleHealth $health) {}

    public function enter(HostBindings $bindings): void
    {
        $this->assertAvailable();
        $this->attach($bindings);
        $this->running = true;
    }

    public function assertAvailable(): void
    {
        $this->health->assertUsable();
        if ($this->running || $this->resetting || $this->poisoned || $this->retired) { throw new \LogicException('Managed scope unavailable'); }
    }

    public function attach(HostBindings $bindings): void
    {
        $this->health->assertUsable();
        if ($this->resetting || $this->poisoned || $this->retired || ($this->bindings !== null && $this->bindings !== $bindings)) {
            throw new \LogicException('Managed scope unavailable');
        }
        $this->bindings = $bindings;
    }

    public function assertActive(): void
    {
        if (!$this->running || $this->poisoned || $this->retired) { throw new ContractViolation(ErrorCode::Cancelled); }
    }

    public function ownsBindings(): bool { return $this->bindings !== null; }
    public function poison(): void { $this->poisoned = true; $this->health->poison(); }

    public function reset(): void
    {
        $bindings = $this->bindings;
        $this->bindings = null;
        $this->running = false;
        if ($bindings !== null) {
            $this->resetting = true;
            try { $bindings->reset(); }
            catch (\Throwable) { $this->poisoned = true; $this->health->poison(); throw new LifecycleFailure(); }
            finally { $this->resetting = false; }
        }
    }

    public function retire(): void
    {
        try { $this->reset(); } finally { $this->retired = true; }
    }
}
