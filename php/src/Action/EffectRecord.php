<?php

declare(strict_types=1);

namespace AgenTools\Action;

final readonly class EffectRecord
{
    public function __construct(
        public string $effectId,
        public EffectStatus $status,
        public int $epoch,
        public int $requestedSeq,
        public ?int $resolvedSeq = null,
        public ?string $toolName = null,
        public ?string $idempotencyKey = null,
        public ?string $externalRef = null,
    ) {
    }

    public function resolve(EffectStatus $status, int $seq, int $epoch, ?string $externalRef): self
    {
        return new self($this->effectId, $status, $epoch, $this->requestedSeq, $seq, $this->toolName, $this->idempotencyKey, $externalRef);
    }
}
