<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament\Profiles;

use AgenToolsPlatform\Core\Common\{InstanceId, Ref};
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Trusted scoped host port; no principal, tenant or grant comes from the form. */
interface Access
{
    public function context(): ExecutionContext;
    public function authorize(ExecutionContext $context, BundleRef $target, Operation $operation, ?InstanceId $instance): void;

    /** Mandatory durable audit: actor/tenant/session, operation/code and opaque refs only. */
    public function record(ExecutionContext $context, BundleRef $target, Operation $operation, string $code, ?Ref $reason, ?Ref $command, ?InstanceId $instance): void;

    /** Also clear any host profile authority/catalog permission caches for this scope. */
    public function reset(): void;
}
