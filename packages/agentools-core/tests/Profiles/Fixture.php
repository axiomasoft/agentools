<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Profiles;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, PayloadHash, Ref, TenantId};
use AgenToolsPlatform\Core\Profiles\{BundleCompiler, BundleManifest, CodeRegistry, CompiledProfile, DefinitionRef, Dependency,
    DependencyCatalog, LayeredCatalog, OwnerScope, Provenance, ResourceKind, ResourceRef};
use AgenToolsPlatform\Tests\JcsAdapter;

final class TenantCatalog implements DependencyCatalog
{
    public bool $revoked = false;
    public int $calls = 0;
    /** @param list<Dependency> $entries */
    public function __construct(public array $entries) {}
    public function resolve(ResourceRef $ref, TenantId $tenant): Dependency
    {
        ++$this->calls;
        $ref->owner->assertVisible($tenant);
        if ($this->revoked || $ref->owner->kind !== 'tenant') { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
        foreach ($this->entries as $entry) {
            if ($entry->ref->key() === $ref->key()) { $entry->ref->version->hash->assertSame($ref->version->hash); return $entry; }
        }
        throw new ContractViolation(ErrorCode::CapabilityUnavailable);
    }
}

/** Scripted compiler for lifecycle tests; P7.2 owns the actual restriction merger. */
final readonly class Compiler implements BundleCompiler
{
    public function version(): DefinitionRef { return new DefinitionRef('profile.compiler', '1', Fixture::hash('compiler')); }
    public function compile(BundleManifest $manifest): CompiledProfile
    {
        if (count($manifest->dependencies) !== 3) { throw new \InvalidArgumentException('Incomplete dependency closure'); }
        return Fixture::compiled($manifest->profile->version->version);
    }
}

final class Fixture
{
    public JcsAdapter $json;
    public TenantId $tenant;
    public TenantCatalog $overlay;
    public LayeredCatalog $catalog;
    public Compiler $compiler;
    public ResourceRef $definition;
    /** @var array{Dependency, Dependency, Dependency} */
    public array $dependencies;

    public function __construct(string $tenant = 'tenant-a')
    {
        $this->json = new JcsAdapter(); $this->tenant = new TenantId($tenant); $this->compiler = new Compiler();
        $this->definition = new ResourceRef(OwnerScope::platform(), ResourceKind::Definition, 'assistant.support', '1', self::hash('definition'));
        $definition = new Dependency($this->definition, self::provenance());
        $deployment = new Dependency(new ResourceRef(OwnerScope::tenant($this->tenant), ResourceKind::Deployment, 'deployment.read', '1', self::hash('deployment')), self::provenance());
        $profiles = [];
        foreach (['7', '8'] as $version) {
            $profiles[] = new Dependency(new ResourceRef(OwnerScope::tenant($this->tenant), ResourceKind::Profile, 'profile.support', $version, self::hash('profile-' . $version)), self::provenance());
        }
        $this->dependencies = [$definition, $deployment, $profiles[0]];
        $this->overlay = new TenantCatalog([$deployment, ...$profiles]);
        $this->catalog = new LayeredCatalog(new CodeRegistry([$definition]), $this->overlay);
    }

    public static function hash(string $text): PayloadHash { return new PayloadHash('sha-256:' . hash('sha256', $text)); }
    public static function provenance(): Provenance { return new Provenance('code:fixture-author', 'validation:scripted', 'evaluation:scripted'); }
    public static function compiled(string $version = '7'): CompiledProfile
    {
        return new CompiledProfile('{"tone":"' . ($version === '8' ? 'concise' : 'neutral') . '"}',
            '{"regions":["RU"],"tools":["search"],"deployment":"deployment.read"}', new JcsAdapter());
    }

    /** @param list<Dependency>|null $dependencies */
    public function manifest(int $version = 7, ?CompiledProfile $compiled = null, ?array $dependencies = null, ?DefinitionRef $compiler = null): BundleManifest
    {
        $profile = new ResourceRef(OwnerScope::tenant($this->tenant), ResourceKind::Profile, 'profile.support', (string) $version, self::hash('profile-' . $version));
        $deps = $dependencies ?? [$this->dependencies[0], $this->dependencies[1], new Dependency($profile, self::provenance())];
        return new BundleManifest(new Ref($this->tenant, 'bundle.support'), $version, $compiler ?? $this->compiler->version(),
            $this->definition, $profile, $deps, $compiled ?? self::compiled((string) $version), self::provenance(), $this->json);
    }
}
