<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament;

use AgenToolsPlatform\Core\Common\{ActionId, RunId, TenantId};

final readonly class Target
{
    public RunId|ActionId $id;

    public function __construct(public string $kind, TenantId $tenant, string $id)
    {
        $this->id = match ($kind) {
            'run' => new RunId($tenant, $id),
            'action' => new ActionId($tenant, $id),
            default => throw new \InvalidArgumentException('Invalid inspector target'),
        };
    }
}
