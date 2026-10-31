<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

final readonly class SourceIdentity
{
    public function __construct(public InstanceId $instance, public string $kind, public string $eventId)
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $kind) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/@-]{0,255}$/D', $eventId) !== 1) {
            throw new \InvalidArgumentException('A source namespace and stable event ID are required');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['instance_ref' => $this->instance->toArray(), 'kind' => $this->kind, 'event_id' => $this->eventId];
    }

    public function key(CanonicalHasher $hasher): PayloadHash
    {
        return $hasher->hash($this->instance->tenant, 'source-identity', $this->toArray());
    }

    public function payloadHash(InputPayload $payload, CanonicalHasher $hasher): PayloadHash
    {
        $this->instance->tenant->assertSame($payload->inputRef->tenant);
        return $hasher->hash($this->instance->tenant, 'source-payload', $payload->toArray());
    }
}
