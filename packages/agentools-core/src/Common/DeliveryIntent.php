<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

final readonly class DeliveryIntent
{
    public function __construct(public DeliveryId $id, public RunId $runId, public int $responseVersion,
        public Ref $destination, public Ref $responseRef, public PayloadHash $requestHash)
    {
        if ($responseVersion < 1 || $responseVersion > 9007199254740991) {
            throw new \InvalidArgumentException('Positive response version required');
        }
        foreach ([$runId, $destination, $responseRef] as $ref) {
            $id->tenant->assertSame($ref->tenant);
        }
    }

    public function assertReplay(self $other): void
    {
        $this->id->assertSame($other->id);
        $this->runId->assertSame($other->runId);
        $this->destination->assertSame($other->destination);
        $this->responseRef->assertSame($other->responseRef);
        $this->requestHash->assertSame($other->requestHash);
        if ($this->responseVersion !== $other->responseVersion) {
            throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Delivery response version changed');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id->toArray(), 'run_id' => $this->runId->toArray(), 'response_version' => $this->responseVersion,
            'destination' => $this->destination->toArray(), 'response_ref' => $this->responseRef->toArray(), 'request_hash' => $this->requestHash->value];
    }
}
