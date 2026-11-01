<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

use AgenToolsPlatform\Core\Common\{ContractViolation, IntegrityConflict, TenantId};

final readonly class BundleValidator
{
    public function __construct(private DependencyCatalog $catalog, private BundleCompiler $compiler) {}

    /** @return list<string> Stable issue codes; failed validation leaves the persisted candidate in draft. */
    public function issues(BundleManifest $manifest): array
    {
        $issues = [];
        try { $this->assertDependencies($manifest, $manifest->bundle->ref->tenant); }
        catch (ContractViolation|IntegrityConflict|\InvalidArgumentException) { $issues[] = 'DEPENDENCY_INVALID'; }
        $expected = $this->compiler->version();
        if ($expected->toArray() !== $manifest->compiler->toArray()) { $issues[] = 'COMPILER_MISMATCH'; }
        if ($issues === []) {
            try {
                $actual = $this->compiler->compile($manifest);
                if ($actual->effectiveJson !== $manifest->compiled->effectiveJson
                    || $actual->restrictionsJson !== $manifest->compiled->restrictionsJson) { $issues[] = 'COMPILER_OUTPUT_MISMATCH'; }
            } catch (ContractViolation|IntegrityConflict|\InvalidArgumentException) { $issues[] = 'COMPILATION_FAILED'; }
        }
        return $issues;
    }

    /** Also called on EVERY new/pinned use; historical dependency references remain in the manifest. */
    public function assertDependencies(BundleManifest $manifest, TenantId $tenant): void
    {
        $manifest->bundle->ref->tenant->assertSame($tenant);
        $seen = [];
        foreach ($manifest->dependencies as $dependency) {
            $ref = $dependency->ref;
            $ref->owner->assertVisible($tenant);
            if (isset($seen[$ref->key()])) { throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Duplicate dependency identity'); }
            $seen[$ref->key()] = $ref->version->hash->value;
            $actual = $this->catalog->resolve($ref, $tenant);
            if ($actual->ref->toArray() !== $ref->toArray() || $actual->provenance->toArray() !== $dependency->provenance->toArray()) {
                throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Dependency hash or provenance mismatch');
            }
        }
        foreach ([$manifest->definition, $manifest->profile] as $required) {
            $required->owner->assertVisible($tenant);
            if (($seen[$required->key()] ?? null) !== $required->version->hash->value) {
                throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Required root absent from dependency closure');
            }
        }
    }
}
