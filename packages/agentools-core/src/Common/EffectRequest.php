<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

final readonly class EffectRequest
{
    public function __construct(
        public ActionId $actionId,
        public EffectId $effectId,
        public PayloadHash $effectKey,
        public Ref $ownerOperationRef,
        public PayloadHash $requestHash,
    ) {
        $actionId->tenant->assertSame($effectId->tenant);
        $actionId->tenant->assertSame($ownerOperationRef->tenant);
    }

    public function assertReplay(self $other): void
    {
        $this->actionId->assertSame($other->actionId);
        $this->effectId->assertSame($other->effectId);
        $this->effectKey->assertSame($other->effectKey);
        $this->ownerOperationRef->assertSame($other->ownerOperationRef);
        $this->requestHash->assertSame($other->requestHash);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId->toArray(),
            'effect_id' => $this->effectId->toArray(),
            'effect_key' => $this->effectKey->value,
            'owner_operation_ref' => $this->ownerOperationRef->toArray(),
            'request_hash' => $this->requestHash->value,
        ];
    }
}
