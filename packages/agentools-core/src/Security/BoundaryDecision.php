<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\{PayloadHash,Ref};

final readonly class BoundaryDecision
{
    public function __construct(public Ref $ref,public Ref $snapshot,public PayloadHash $hash,public EntryPoint $entry,public BoundaryStage $stage) { $ref->tenant->assertSame($snapshot->tenant); }
    /** @return array<string,mixed> */
    public function toArray(): array { return ['decision_ref'=>$this->ref->toArray(),'snapshot_ref'=>$this->snapshot->toArray(),'snapshot_hash'=>$this->hash->value,'entry'=>$this->entry->value,'stage'=>$this->stage->value,'code'=>'allowed']; }
}
