<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash};
use AgenToolsPlatform\Core\Profiles\{Dependency, OwnerScope, Provenance};

/** Immutable typed content; a cached owner/hash cannot silently relabel another document. */
final readonly class PolicyLayer
{
    public Dependency $dependency;
    public function __construct(OwnerScope $owner, string $id, string $version, public LayerRole $role,
        public HardConstraints $hard, Provenance $provenance, CanonicalJson $json,
        public ?DefaultSettings $defaults = null, public ?EditableSettings $editable = null,
        public ?StyleOverride $style = null,
        public MaterialChanges $materials = new \AgenToolsPlatform\Core\Profiles\Composition\MaterialChanges(),
        public MethodologySelection $methodology = new \AgenToolsPlatform\Core\Profiles\Composition\MethodologySelection(),
        public string $methodologyCompatibility = 'none', ?PayloadHash $expectedHash = null)
    {
        if (($defaults !== null || $editable !== null) && $role !== LayerRole::Definition) { throw new \InvalidArgumentException('Only code definition declares defaults/editable fields'); }
        if (($methodology->packs !== [] || $methodology->composition !== null) && $role !== LayerRole::Profile) { throw new \InvalidArgumentException('Profile must explicitly select methodology'); }
        Values::set([$methodologyCompatibility]);
        $this->dependency = Document::dependency($owner, $role->resourceKind(), $id, $version, $provenance, [
            'format'=>'profile-policy-layer/v1', 'role'=>$role->name, 'hard'=>$hard->toArray(),
            'defaults'=>$defaults?->toArray(), 'editable'=>$editable?->toArray(), 'style'=>$style?->toArray(),
            'materials'=>$materials->toArray(), 'methodology'=>$methodology->toArray(),
            'methodology_compatibility'=>$methodologyCompatibility], $json, $expectedHash);
    }
}
