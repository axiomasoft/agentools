<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Payloads;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Host-owned current source/data ACL, classification/purpose and retention authority. */
interface PayloadPolicy
{
    /** Return an auditable current decision ref or throw; a pinned ref alone grants nothing. */
    public function authorize(ExecutionContext $context, PayloadDescriptor $payload, string $operation): Ref;
}
