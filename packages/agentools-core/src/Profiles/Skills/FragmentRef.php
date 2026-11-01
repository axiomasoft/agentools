<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Profiles\{DefinitionRef, OwnerScope, Provenance};

final readonly class FragmentRef
{
    public DefinitionRef $version;
    public function __construct(public OwnerScope $owner, string $id, string $version, PayloadHash $hash, public Provenance $provenance)
    {
        $this->version = new \AgenToolsPlatform\Core\Profiles\DefinitionRef($id, $version, $hash);
    }
    public function key(): string { return json_encode([$this->owner->toArray(),$this->version->id,$this->version->version], JSON_THROW_ON_ERROR); }
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array { return ['owner'=>$this->owner->toArray()]+$this->version->toArray()+['provenance'=>$this->provenance->toArray()]; }
}
