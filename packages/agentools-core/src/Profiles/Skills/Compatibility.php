<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Profiles\{BundleManifest, DefinitionRef, ResourceKind, ResourceRef};

final readonly class Compatibility
{
    public function __construct(public ResourceRef $definition, public ResourceRef $profile, public DefinitionRef $compiler)
    {
        if ($definition->kind !== ResourceKind::Definition || $profile->kind !== ResourceKind::Profile) { throw new \InvalidArgumentException('Exact typed compatibility roots required'); }
    }
    public function matches(BundleManifest $bundle): bool
    {
        return $bundle->definition->toArray() === $this->definition->toArray()
            && $bundle->profile->toArray() === $this->profile->toArray() && $bundle->compiler->toArray() === $this->compiler->toArray();
    }
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array { return ['definition'=>$this->definition->toArray(),'profile'=>$this->profile->toArray(),'compiler'=>$this->compiler->toArray()]; }
}
