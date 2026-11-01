<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash};
use AgenToolsPlatform\Core\Profiles\{Dependency, OwnerScope, Provenance, ResourceKind};

final readonly class MethodologyPack
{
    public Dependency $dependency;
    public function __construct(OwnerScope $owner, string $id, string $version, public string $compatibility,
        Provenance $provenance, CanonicalJson $json, ?PayloadHash $expectedHash = null)
    {
        Values::set([$compatibility]);
        $this->dependency = Document::dependency($owner, ResourceKind::Methodology, $id, $version, $provenance,
            ['format'=>'methodology-pack/v1', 'compatibility'=>$compatibility], $json, $expectedHash);
    }
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array { return ['dependency'=>$this->dependency->toArray(), 'compatibility'=>$this->compatibility]; }
}
