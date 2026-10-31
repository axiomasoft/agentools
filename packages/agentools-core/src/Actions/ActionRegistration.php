<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

final readonly class ActionRegistration
{
    public function __construct(public ActionDefinition $definition, public ActionHandler $handler)
    {
        if ($definition->ownerHandlerId !== $handler->handlerId()) {
            throw new \InvalidArgumentException('Code handler does not match the pinned definition');
        }
    }
}
