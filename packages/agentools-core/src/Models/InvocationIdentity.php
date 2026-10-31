<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

use AgenToolsPlatform\Core\Common\InvocationId;
use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\RunId;
use AgenToolsPlatform\Core\Common\UtcInstant;

/** One provider request identity; fallback links a new invocation, never a new Action. */
final readonly class InvocationIdentity
{
    public function __construct(public InvocationId $id, public RunId $runId,
        public ?InvocationId $parentInvocationId, public Ref $requestRef, public PayloadHash $requestHash, public UtcInstant $deadline)
    {
        foreach ([$runId, $parentInvocationId, $requestRef] as $ref) {
            if ($ref !== null) {
                $id->tenant->assertSame($ref->tenant);
            }
        }
        if ($parentInvocationId !== null && $id->value === $parentInvocationId->value) {
            throw new \InvalidArgumentException('Fallback must have a new invocation identity');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id->toArray(), 'run_id' => $this->runId->toArray(),
            'parent_invocation_id' => $this->parentInvocationId?->toArray(), 'request_ref' => $this->requestRef->toArray(),
            'request_hash' => $this->requestHash->value, 'deadline' => $this->deadline->value];
    }
}
