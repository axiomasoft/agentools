<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Confirmation\AuthenticatedActor;
use AgenTools\Confirmation\AuthorizationContext;
use AgenTools\Confirmation\ResolvedPolicy;

interface ConfirmationPolicyResolver
{
    public function resolvePolicy(
        AuthenticatedActor $actor,
        AuthorizationContext $context,
        string $actionId,
        string $toolName,
    ): ?ResolvedPolicy;
}
