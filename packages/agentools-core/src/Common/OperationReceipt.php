<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

/** Immutable observed outcome; a timeout is not a known failure. */
final readonly class OperationReceipt
{
    public function __construct(
        public InvocationId|EffectId|DeliveryId $operationId,
        public PayloadHash $requestHash,
        public Outcome $outcome,
        public Certainty $certainty,
        public ?Ref $resultRef,
    ) {
        if (($outcome === Outcome::Unknown) !== ($certainty === Certainty::Unknown)
            || ($outcome !== Outcome::Unknown && $resultRef === null)) {
            throw new \InvalidArgumentException('Known outcomes require a result/evidence ref; unknown stays separate');
        }
        if ($resultRef !== null) {
            $operationId->tenant->assertSame($resultRef->tenant);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['operation_id' => $this->operationId->toArray(), 'request_hash' => $this->requestHash->value,
            'outcome' => $this->outcome->value, 'certainty' => $this->certainty->value, 'result_ref' => $this->resultRef?->toArray()];
    }
}
