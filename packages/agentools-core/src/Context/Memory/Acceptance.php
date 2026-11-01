<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\Memory;

use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};

final readonly class Acceptance
{
    public function __construct(public Ref $decision,public Ref $owner,public UtcInstant $confirmedAt,public bool $manual)
    { $decision->tenant->assertSame($owner->tenant); }
    /** @return array<string,mixed> */
    public function toArray(): array { return ['decision_ref'=>$this->decision->toArray(),'owner_ref'=>$this->owner->toArray(),'confirmed_at'=>$this->confirmedAt->value,'manual'=>$this->manual]; }
}
