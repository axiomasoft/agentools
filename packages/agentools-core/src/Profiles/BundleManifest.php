<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash, Ref};

final readonly class BundleManifest
{
    public BundleRef $bundle;
    /** @var list<Dependency> */
    public array $dependencies;

    /** @param array<array-key, Dependency> $dependencies */
    public function __construct(private Ref $id, private int $version, public DefinitionRef $compiler, public ResourceRef $definition,
        public ResourceRef $profile, array $dependencies, public CompiledProfile $compiled,
        public Provenance $provenance, CanonicalJson $json, ?PayloadHash $expectedHash = null)
    {
        if ($definition->kind !== ResourceKind::Definition || $profile->kind !== ResourceKind::Profile || !array_is_list($dependencies)) {
            throw new \InvalidArgumentException('Typed definition/profile and complete dependency list required');
        }
        // Input order has no semantic significance; duplicate identities are rejected during validation.
        usort($dependencies, static fn (Dependency $a, Dependency $b): int => strcmp($a->ref->key(), $b->ref->key()));
        $this->dependencies = $dependencies;
        $hash = new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:' . hash('sha256', $json->canonicalize($this->content())));
        $expectedHash?->assertSame($hash);
        $this->bundle = new \AgenToolsPlatform\Core\Profiles\BundleRef($id, $version, $hash);
    }

    /** @return array<string, mixed> */
    public function content(): array
    {
        return ['format' => 'profile-bundle/v1', 'owner' => OwnerScope::tenant($this->id->tenant)->toArray(),
            'id' => $this->id->value, 'version' => $this->version,
            'compiler' => $this->compiler->toArray(), 'definition' => $this->definition->toArray(),
            'profile' => $this->profile->toArray(), 'dependencies' => array_map(static fn (Dependency $d): array => $d->toArray(), $this->dependencies),
            'compiled' => $this->compiled->toArray(), 'provenance' => $this->provenance->toArray()];
    }

    /** @return array<string, mixed> */
    public function toArray(): array { return $this->content() + ['content_hash' => $this->bundle->hash->value]; }
}
