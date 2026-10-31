<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing;

use AgenToolsPlatform\Core\Common\{EffectId, OperationReceipt, PayloadHash, Ref};

final readonly class OwnerLookupStep
{
    public function __construct(public EffectId $effectId, public Ref $savedOperationKey, public PayloadHash $requestHash, public ?OperationReceipt $reply)
    {
        $effectId->tenant->assertSame($savedOperationKey->tenant);
        if ($reply !== null) {
            $effectId->assertSame($reply->operationId);
            $requestHash->assertSame($reply->requestHash);
        }
    }
}
