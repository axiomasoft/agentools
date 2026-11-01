<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Profiles\ResourceRef;

final readonly class Qualification
{
    public function __construct(public ResourceRef $ref, public PayloadHash $tupleHash,
        public QualificationDecision $decision, public string $evidenceTier)
    {
        if (!in_array($evidenceTier,['contract-scripted','isolated-postgres','local-host-e2e','live-provider','product-pilot'],true)) {
            throw new \InvalidArgumentException('Explicit qualification evidence tier required');
        }
    }
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array { return ['ref'=>$this->ref->toArray(),'tuple_hash'=>$this->tupleHash->value,'decision'=>$this->decision->value,'evidence_tier'=>$this->evidenceTier]; }
}
