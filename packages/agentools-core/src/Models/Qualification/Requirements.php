<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Qualification;
use AgenToolsPlatform\Core\Common\{PayloadHash, Ref};
/** Current owner requirements for this profile/bundle; null source result denies. */
final readonly class Requirements
{
    public function __construct(public Ref $profile, public PayloadHash $bundleHash,
        public PayloadHash $policyHash, public EvidencePolicy $evidencePolicy) {}
}
