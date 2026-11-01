<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Effects;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref};

/** Known receipts and absence proofs are bound to the exact tenant/namespace/key/hash. */
final readonly class LookupResult
{
    public function __construct(public LookupStatus $status, public Ref $operation,
        public string $ownerNamespace, public PayloadHash $requestHash, public ?Ref $receipt = null)
    {
        if ($receipt !== null) { $operation->tenant->assertSame($receipt->tenant); }
        if ($ownerNamespace === '' || strlen($ownerNamespace) > 512
            || (in_array($status, [LookupStatus::Succeeded, LookupStatus::Failed, LookupStatus::Absent], true) && $receipt === null)) {
            throw new \InvalidArgumentException('Known outcome or absence requires owner evidence');
        }
    }

    public function assertRequest(Request $request): void
    {
        $request->action->tenant->assertSame($this->operation->tenant);
        if ($this->operation->value !== $request->operationKey || $this->ownerNamespace !== $request->ownerNamespace
            || $this->requestHash->value !== $request->requestHash->value) {
            throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Owner outcome request binding conflict');
        }
    }
}
