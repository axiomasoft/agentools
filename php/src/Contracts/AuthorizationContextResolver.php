<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Confirmation\AuthenticatedActor;
use AgenTools\Confirmation\AuthorizationContext;

interface AuthorizationContextResolver
{
    public function resolveContext(AuthenticatedActor $actor, string $actionId, string $toolName): AuthorizationContext;
}
