<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\Ref;

final readonly class DisclosurePolicy
{
    public function __construct(public Ref $ref,public string $version,public DisclosureMode $mode,public bool $sensitive,public int $maxBytes,public ?Ref $liveApproval=null)
    {
        if ($version==='' || $maxBytes<1 || $maxBytes>9007199254740991 || ($sensitive && $mode!==DisclosureMode::Buffered)
            || ($mode===DisclosureMode::Live && $liveApproval===null)) { throw new \InvalidArgumentException('Sensitive buffered policy, bounded output and explicit live approval required'); }
        if ($liveApproval!==null) { $ref->tenant->assertSame($liveApproval->tenant); }
    }
    /** @return array<string,mixed> */
    public function toArray(): array { return ['policy_ref'=>$this->ref->toArray(),'version'=>$this->version,'mode'=>$this->mode->value,'sensitive'=>$this->sensitive,'max_bytes'=>$this->maxBytes,'live_approval_ref'=>$this->liveApproval?->toArray()]; }
}
