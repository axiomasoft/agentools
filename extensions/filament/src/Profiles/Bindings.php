<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament\Profiles;

use AgenToolsPlatform\Core\Profiles\Composition\TypedCompiler;
use AgenToolsPlatform\Laravel\Managed\{HostBindings, ProductionBinding};
use AgenToolsPlatform\Postgres\Profiles\{BundleRepository, SessionRepository};

/** Host constructs the canonical P7.1 services and P7.2 compiler in trusted code. */
final readonly class Bindings implements ProductionBinding
{
    public function __construct(public BundleRepository $bundles, public SessionRepository $sessions,
        public TypedCompiler $compiler, public Access $access) {}

    public function assertProduction(): void
    {
        foreach ([$this->bundles, $this->sessions, $this->compiler, $this->access] as $service) {
            HostBindings::assertProductionService($service);
        }
    }
}
