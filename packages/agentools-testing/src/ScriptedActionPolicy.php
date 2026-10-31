<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing;

use AgenToolsPlatform\Core\Actions\{ActionAccess, ActionPolicy};
use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Scripts current external policy only; the registry's intersection remains authoritative. */
final class ScriptedActionPolicy implements ActionPolicy
{
    /** @var array<string, ActionAccess> */
    private array $current = [];
    public function set(TenantId $tenant, ActionAccess $access): void { $this->current['tenant:' . $tenant->value] = $access; }
    public function reset(): void { $this->current = []; }
    public function current(ExecutionContext $context): ActionAccess
    {
        return $this->current['tenant:' . $context->identity->tenant->value] ?? new ActionAccess([], [], [], [], [], []);
    }
}
