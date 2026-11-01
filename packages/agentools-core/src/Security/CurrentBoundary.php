<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\{PayloadHash,Ref};

/** Fresh owner observation for one boundary check; not reusable as a capability. */
final readonly class CurrentBoundary
{
    /** @param array<array-key,ToolPin> $tools */
    public function __construct(public Ref $decision,public AccessState $access,public string $policyRevision,
        public ?int $snapshotRevision,public ?PayloadHash $snapshotHash,public ?bool $emergencyStop,public array $tools)
    { if (!array_is_list($tools) || $policyRevision==='') { throw new \InvalidArgumentException('Current boundary observation required'); } }
}
