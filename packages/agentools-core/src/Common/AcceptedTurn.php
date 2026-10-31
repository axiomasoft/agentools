<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

/** Receipt value constructed by a store after atomic turn/run/outbox acceptance. */
final readonly class AcceptedTurn
{
    public function __construct(
        public PayloadHash $sourceKey,
        public PayloadHash $payloadHash,
        public TurnId $turnId,
        public RunId $runId,
        public RunStatus $status = RunStatus::Accepted,
    ) {
        $turnId->tenant->assertSame($runId->tenant);
    }

    public function replay(SourceIdentity $source, PayloadHash $payloadHash, CanonicalHasher $hasher): self
    {
        $this->turnId->tenant->assertSame($source->instance->tenant);
        $this->sourceKey->assertSame($source->key($hasher));
        $this->payloadHash->assertSame($payloadHash);
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['source_key' => $this->sourceKey->value, 'source_payload_hash' => $this->payloadHash->value,
            'turn_id' => $this->turnId->toArray(), 'run_id' => $this->runId->toArray(), 'status' => $this->status->value];
    }
}
