<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament;

use AgenToolsPlatform\Laravel\Managed\{HostBindings, ProductionBinding};
use Illuminate\Container\Container;

final readonly class Bindings implements ProductionBinding
{
    public function __construct(public HostBindings $runtime, public Access $access, public ?Diagnostics $diagnostics = null) {}

    public function assertProduction(): void
    {
        $this->runtime->assertProduction();
        HostBindings::assertProductionService($this->access);
        if ($this->diagnostics !== null) { HostBindings::assertProductionService($this->diagnostics); }
    }

    public function assertEnvironment(Container $app): void
    {
        $this->runtime->assertEnvironment($app);
        if (!$app->bound('agentools.testing') || $app->make('agentools.testing') !== true) { $this->assertProduction(); }
    }
}
