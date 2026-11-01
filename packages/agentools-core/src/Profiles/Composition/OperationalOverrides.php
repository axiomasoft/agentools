<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash};
use AgenToolsPlatform\Core\Profiles\{Dependency, OwnerScope, Provenance, ResourceKind};

/** A fresh bounded input, separate from defaults/editable fields and immutable pinned policy. */
final readonly class OperationalOverrides
{
    public Dependency $dependency;
    public function __construct(OwnerScope $owner, string $id, string $version, public OperationalRole $role,
        public HardConstraints $hard, Provenance $provenance, CanonicalJson $json,
        public ?DeploymentPreference $preference = null, ?PayloadHash $expectedHash = null)
    {
        $this->dependency = Document::dependency($owner, ResourceKind::ExecutionPolicy, $id, $version, $provenance,
            ['format'=>'profile-operational-overrides/v1', 'role'=>$role->name, 'hard'=>$hard->toArray(),
                'preference'=>$preference?->toArray()], $json, $expectedHash);
    }
}
