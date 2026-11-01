<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament;

use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Trusted host port. Never hydrate context/permissions from URL or Livewire properties. */
interface Access
{
    /** Authenticate the CURRENT request/job principal and select its trusted tenant/session. */
    public function context(): ExecutionContext;

    /** Recheck actor, tenant, resource, purpose and current inspect/diagnostics permission. */
    public function authorize(ExecutionContext $context, Target $target, string $permission): void;

    /** Mandatory durable audit. Record tenant/actor/session/target/permission/code only. */
    public function record(ExecutionContext $context, Target $target, string $permission, string $code): void;

    /** Clear principal, credentials, diagnostic handles and permission caches. */
    public function reset(): void;
}
