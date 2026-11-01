<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash};
use AgenToolsPlatform\Core\Profiles\{Dependency, OwnerScope, Provenance, ResourceKind, ResourceRef};

/** A separately pinned approval of an exact ordered pack composition. */
final readonly class MethodologyComposition
{
    public Dependency $dependency;
    /**
     * @param list<ResourceRef> $packs
     */
    public function __construct(OwnerScope $owner, string $id, string $version, public string $compatibility,
        public array $packs, Provenance $provenance, CanonicalJson $json, ?PayloadHash $expectedHash = null)
    {
        Values::set([$compatibility]);
        if (count($packs) < 2 || count($packs) > 16) { throw new \InvalidArgumentException('Bounded explicit methodology composition required'); }
        $seen = [];
        foreach ($packs as $pack) {
            if ($pack->kind !== ResourceKind::Methodology || isset($seen[$pack->key()])) { throw new \InvalidArgumentException('Distinct methodology packs required'); }
            $seen[$pack->key()] = true;
        }
        $this->dependency = Document::dependency($owner, ResourceKind::Methodology, $id, $version, $provenance,
            ['format'=>'methodology-composition/v1', 'compatibility'=>$compatibility,
                'packs'=>array_map(static fn (ResourceRef $r): array => $r->toArray(), $packs)], $json, $expectedHash);
    }
}
