<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Profiles\Merge;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref, TenantId};
use AgenToolsPlatform\Core\Profiles\{BundleManifest, CodeRegistry, DefinitionRef, Dependency, DependencyCatalog,
    LayeredCatalog, OwnerScope, Provenance, ResourceKind, ResourceRef, UseBoundary};
use AgenToolsPlatform\Core\Profiles\Composition\{CompilationInput, CompiledComposition, Composer, ConfirmationFloor, DefaultSettings,
    Denials, DeploymentPreference, EditableSettings, HardConstraints, InputCatalog, LayerRole, MaterialAccessPolicy,
    MaterialChanges, MethodologySelection, OperationalOverrides, OperationalRole, PolicyLayer, RegisteredTool,
    SafetyFloor, StyleOverride, Tone, ToolRegistry, TypedCompiler};
use AgenToolsPlatform\Tests\JcsAdapter;

final class ScriptedMaterials implements MaterialAccessPolicy
{
    /**
     * @var list<string>
     */ public array $calls = [];
    public bool $denied = false;
    public bool $unknown = false;
    public function assertCurrent(TenantId $tenant, ResourceRef $material, UseBoundary $boundary): void
    {
        $tenant->assertSame($material->owner->tenant ?? $tenant);
        $this->calls[] = $boundary->value . ':' . $material->version->id;
        if ($this->unknown) { throw new \RuntimeException('Material owner unavailable'); }
        if ($this->denied) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
}

final class Catalog implements InputCatalog, DependencyCatalog
{
    /**
     * @var array<string, CompilationInput>
     */ public array $inputs = [];
    /**
     * @var array<string, Dependency>
     */ public array $entries = [];
    public ?CompilationInput $corrupted = null;
    public function load(BundleManifest $manifest): CompilationInput
    {
        return $this->corrupted ?? $this->inputs[$manifest->profile->key()] ?? throw new ContractViolation(ErrorCode::CapabilityUnavailable);
    }
    public function resolve(ResourceRef $ref, TenantId $tenant): Dependency
    {
        $ref->owner->assertVisible($tenant);
        $entry = $this->entries[$ref->key()] ?? throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        $ref->version->hash->assertSame($entry->ref->version->hash); return $entry;
    }
    public function register(CompilationInput $input): void
    {
        foreach ($input->dependencies() as $dependency) {
            $key = $dependency->ref->key();
            if (isset($this->entries[$key]) && $this->entries[$key]->toArray() !== $dependency->toArray()) { throw new IntegrityConflict('Fixture dependency conflict'); }
            $this->entries[$key] = $dependency;
        }
        foreach ($input->layers as $layer) { if ($layer->role === LayerRole::Profile) { $this->inputs[$layer->dependency->ref->key()] = $input; } }
    }
    public function layered(): LayeredCatalog
    {
        return new LayeredCatalog(new CodeRegistry(array_values(array_filter($this->entries,
            static fn (Dependency $d): bool => $d->ref->owner->kind === 'platform'))), $this);
    }
}

final class Fixture
{
    public JcsAdapter $json;
    public TenantId $tenant;
    public ScriptedMaterials $materials;
    public Catalog $catalog;
    public Composer $composer;
    public TypedCompiler $compiler;
    public function __construct(string $tenant = 'tenant-a')
    {
        $this->json = new JcsAdapter(); $this->tenant = new TenantId($tenant);
        $this->materials = new ScriptedMaterials(); $this->catalog = new Catalog();
        $this->composer = new Composer($this->json, $this->materials);
        $this->compiler = new TypedCompiler(new DefinitionRef('profile.typed-compiler', '1', self::hash('typed-compiler/v1')), $this->catalog, $this->composer, $this->json);
    }
    public static function hash(string $text): PayloadHash { return new PayloadHash('sha-256:' . hash('sha256', $text)); }
    public static function provenance(): Provenance { return new Provenance('code:merge-fixture', 'validation:scripted', 'evaluation:scripted'); }
    /**
     * @param array<array-key, mixed> $regions
     * @param array<array-key, mixed> $deployments
     * @param array<array-key, mixed> $scopes
     * @param array<array-key, mixed> $tools
     * @param array<array-key, mixed> $capabilities
     * @param array<array-key, mixed> $required
     */
    public function hard(array $regions = ['RU','EU'], array $deployments = ['deployment.a','deployment.b'],
        array $scopes = ['public','tenant'], array $tools = ['search','send'], int $budget = 1000,
        ConfirmationFloor $confirmation = ConfirmationFloor::None, SafetyFloor $safety = SafetyFloor::Baseline,
        Denials $denied = new Denials(), array $capabilities = ['read','tool:search','tool:send'], array $required = ['read']): HardConstraints
    {
        return new HardConstraints($regions, $deployments, $scopes, $tools, $budget, $confirmation, $safety, $denied, $capabilities, $required);
    }
    public function layer(LayerRole $role, ?HardConstraints $hard = null, string $version = '1', ?StyleOverride $style = null,
        ?EditableSettings $editable = null, ?MaterialChanges $materials = null, ?MethodologySelection $methodology = null,
        string $compatibility = 'none', ?OwnerScope $owner = null, ?string $id = null): PolicyLayer
    {
        $id ??= match ($role) { LayerRole::Definition=>'assistant.support', LayerRole::Platform=>'policy.platform', LayerRole::Tenant=>'policy.tenant',
            LayerRole::Profile=>'profile.support', LayerRole::Skill=>'skill.read', LayerRole::Lesson=>'lesson.style' };
        $owner ??= in_array($role, [LayerRole::Definition, LayerRole::Platform], true) ? OwnerScope::platform() : OwnerScope::tenant($this->tenant);
        return new PolicyLayer($owner, $id, $version, $role, $hard ?? $this->hard(), self::provenance(), $this->json,
            $role === LayerRole::Definition ? new DefaultSettings(Tone::Neutral, 'Support') : null,
            $role === LayerRole::Definition ? ($editable ?? new EditableSettings(true, true)) : null, $style,
            $materials ?? new MaterialChanges(), $methodology ?? new MethodologySelection(), $compatibility);
    }
    /**
     * @param array<int, PolicyLayer> $replace
     * @param list<PolicyLayer> $extra
     */
    public function input(array $replace = [], array $extra = [], string $version = '7'): CompilationInput
    {
        $layers = [
            LayerRole::Definition->value=>$this->layer(LayerRole::Definition),
            LayerRole::Platform->value=>$this->layer(LayerRole::Platform, $this->hard(regions:['RU'], confirmation:ConfirmationFloor::Required, safety:SafetyFloor::Strict)),
            LayerRole::Tenant->value=>$this->layer(LayerRole::Tenant, $this->hard(budget:600)),
            LayerRole::Profile->value=>$this->layer(LayerRole::Profile, $this->hard(budget:400), $version, new StyleOverride($version === '8' ? Tone::Concise : Tone::Friendly)),
            LayerRole::Skill->value=>$this->layer(LayerRole::Skill, $this->hard(tools:['search'], budget:300)),
        ];
        foreach ($replace as $role => $layer) { $layers[$role] = $layer; }
        $tools = [];
        foreach (['search','send'] as $id) { $tools[] = new RegisteredTool(new DefinitionRef($id, '1', self::hash('tool-'.$id)),
            '{"type":"object","properties":{"'.$id.'_only":{"type":"string"}}}', $this->json); }
        $registry = new ToolRegistry('tools.support', '1', $tools, self::provenance(), $this->json);
        $deployments = [];
        foreach (['deployment.a','deployment.b'] as $id) { $deployments[] = new Dependency(new ResourceRef(OwnerScope::tenant($this->tenant), ResourceKind::Deployment, $id, '1', self::hash($id)), self::provenance()); }
        return new CompilationInput($this->tenant, [...array_values($layers), ...$extra], $registry, $deployments);
    }
    public function operational(OperationalRole $role, ?HardConstraints $hard = null, ?DeploymentPreference $preference = null): OperationalOverrides
    {
        $hard ??= $this->hard(budget:200);
        $version = substr(hash('sha256', $this->json->canonicalize([$hard->toArray(),$preference?->toArray()])), 0, 12);
        return new OperationalOverrides(OwnerScope::tenant($this->tenant), 'current.'.strtolower($role->name), $version, $role,
            $hard, self::provenance(), $this->json, $preference);
    }
    public function material(string $id): Dependency
    {
        return new Dependency(new ResourceRef(OwnerScope::tenant($this->tenant), ResourceKind::Knowledge, $id, '1', self::hash($id)), self::provenance());
    }
    public function bundle(?CompilationInput $input = null, int $version = 7): BundleManifest
    {
        $input ??= $this->input(version:(string)$version); $this->catalog->register($input);
        $compiled = $this->composer->compile($input);
        if (!$compiled instanceof CompiledComposition) { throw new \RuntimeException('Valid fixture required: '.$compiled->code); }
        $definition = null; $profile = null;
        foreach ($input->layers as $layer) {
            if ($layer->role === LayerRole::Definition) { $definition = $layer->dependency->ref; }
            if ($layer->role === LayerRole::Profile) { $profile = $layer->dependency->ref; }
        }
        if ($definition === null || $profile === null) { throw new \RuntimeException('Fixture roots required'); }
        return new BundleManifest(new Ref($this->tenant, 'bundle.typed'), $version, $this->compiler->version(), $definition, $profile,
            $input->dependencies(), $compiled->compiled, self::provenance(), $this->json);
    }
}
