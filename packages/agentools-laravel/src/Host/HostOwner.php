<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Host;

use AgenToolsPlatform\Core\Context\{ContextAccess, ContextBlock};
use AgenToolsPlatform\Core\Security\{ExecutionContext, HostAuthorization};
use AgenToolsPlatform\Laravel\Managed\Input;

/** Explicit server-injected public owner APIs. No host table/container access. */
interface HostOwner extends HostAuthorization, ContextAccess
{
    /** Authenticate current principal, then resolve handle and its tenant/session/purpose. */
    public function request(Input $handle): HostRequest;
    public function state(ExecutionContext $context): ContextState;
    /** @return array<array-key, ContextBlock> Approved projections; Boundary validates a bounded list. */
    public function slices(ExecutionContext $context, ContextState $state): array;
    /** Clear principal, credentials, payload and policy caches at request/job teardown. */
    public function reset(): void;
}
