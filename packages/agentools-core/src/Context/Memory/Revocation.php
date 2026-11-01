<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\Memory;

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode};
use AgenToolsPlatform\Core\Context\History\{Derivations,MessageRef};
use AgenToolsPlatform\Core\Security\ExecutionContext;

final readonly class Revocation
{
    public function __construct(private Derivations $derivations,private MemorySource $owner,private MemoryPolicy $policy) {}
    public function sourceChanged(ExecutionContext $context,MessageRef $source,string $reason): DeleteRequest
    {
        $context->session->tenant->assertSame($source->ref->tenant);
        if (!in_array($reason,['source_revoked','source_deleted','source_corrected','consent_revoked'],true)) { throw new \InvalidArgumentException('Known invalidation reason required'); }
        $this->policy->authorize($context,$source->ref,'memory.revoke_source');
        $this->derivations->invalidate($context,$source,$reason);
        $request=$this->owner->revokeSource($context,$source,$reason);$source->ref->tenant->assertSame($request->ref->tenant);return $request;
    }
}
