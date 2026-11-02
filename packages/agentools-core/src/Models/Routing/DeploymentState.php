<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

final readonly class DeploymentState
{
    /** @param list<Qualification> $qualifications */
    public function __construct(public CatalogEntry $entry, public AdminState $admin, public Health $health,
        public int $version, public array $qualifications = [])
    {
        if ($version < 1) { throw new \InvalidArgumentException('Positive state version required'); }
    }
}
