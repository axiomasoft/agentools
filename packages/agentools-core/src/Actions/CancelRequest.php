<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Cancellation is a durable request; it never rolls back a known effect. */
final readonly class CancelRequest
{
    public function __construct(public Ref $commandId, public int $expectedVersion, public ExecutionContext $context)
    {
        $context->identity->tenant->assertSame($commandId->tenant);
        if ($expectedVersion < 1 || $expectedVersion > 9007199254740991) {
            throw new \InvalidArgumentException('Expected a positive version');
        }
    }
}
