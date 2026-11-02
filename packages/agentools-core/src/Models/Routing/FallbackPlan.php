<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

use AgenToolsPlatform\Core\Common\{InvocationId, Ref};

/** A host must durably open the returned Invocation through its existing journal before IO. */
final readonly class FallbackPlan
{
    public bool $retryActions;
    public function __construct(public string $outcome, public FallbackState $origin, public ?RouteDecision $route = null,
        public ?InvocationId $invocation = null, public ?Ref $segment = null)
    { $this->retryActions = false; }
}
