<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\DirectAction;

use AgenToolsPlatform\Core\Actions\{ActionAccess, ActionDefinition, ActionPolicy};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Local scripted policy only; replacement hosts retain their own operational guard source. */
final class Policy implements ActionPolicy
{
    public bool $available = true;
    public function __construct(private ActionDefinition $definition) {}
    public function current(ExecutionContext $context): ActionAccess
    {
        $keys = [ActionAccess::key($this->definition->ref)];
        return new ActionAccess($keys, $keys, $keys, $keys, $this->available ? $keys : [], ['records.read']);
    }
}
