<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament;

use AgenToolsPlatform\Laravel\Managed\{LifecycleFailure, LifecycleHealth};

/** Tracks even partially resolved admin ports, without putting principals in a singleton. */
final class Scope
{
    /** @var array<int, Access|Diagnostics|Profiles\Access> */
    private array $ports = [];
    private ?Inspector $inspector = null;
    private ?Profiles\Admin $profiles = null;
    private bool $retired = false;

    public function __construct(private readonly LifecycleHealth $health) {}

    public function attach(Access|Diagnostics|Profiles\Access $port): void
    {
        $this->health->assertUsable();
        if ($this->retired) { throw new InspectionFailure('scope_retired'); }
        $this->ports[spl_object_id($port)] = $port;
    }

    public function inspector(Inspector $inspector): void { $this->inspector = $inspector; }
    public function profiles(Profiles\Admin $profiles): void { $this->profiles = $profiles; }

    public function retire(): void
    {
        if ($this->retired) { return; }
        $this->retired = true;
        $this->inspector?->retire();
        $this->profiles?->retire();
        $this->profiles = null;
        $ports = $this->ports; $this->ports = []; $this->inspector = null;
        $failed = false;
        foreach ($ports as $port) {
            try { $port->reset(); } catch (\Throwable) { $failed = true; }
        }
        if ($failed) { $this->health->poison(); throw new LifecycleFailure(); }
    }
}
