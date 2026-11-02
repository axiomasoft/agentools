<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

final readonly class CatalogView
{
    /**
     * @param list<DeploymentState> $deployments
     * @param list<EmergencyRule> $emergency
     */
    public function __construct(public array $deployments, public array $emergency = [])
    {
        $seen = [];
        foreach ($deployments as $state) {
            $key = $state->entry->deployment->ref->tenant->value . ':' . $state->entry->deployment->ref->value;
            if (in_array($key, $seen, true)) { throw new \InvalidArgumentException('Duplicate catalog deployment'); }
            $seen[] = $key;
        }
    }
}
