<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Common\{CanonicalJson, ContractViolation, IntegrityConflict};
use AgenToolsPlatform\Core\Profiles\{BundleCompiler, BundleManifest, CompiledProfile, DefinitionRef, Dependency};

/** P7.1 compiler adapter. Publication and active pin reconstruction use exactly the same typed rules. */
final readonly class TypedCompiler implements \AgenToolsPlatform\Core\Profiles\BundleCompiler
{
    public function __construct(private DefinitionRef $revision, private InputCatalog $catalog, private Composer $composer, private CanonicalJson $json) {}
    public function version(): DefinitionRef { return $this->revision; }

    /**
     * @phpstan-impure Loads pinned inputs and checks current material owner policy.
     */
    public function compile(BundleManifest $manifest): CompiledProfile
    {
        $result = $this->resolve($manifest);
        if ($result instanceof ProfileConflict) { throw new \AgenToolsPlatform\Core\Profiles\Composition\CompilationFailure($result); }
        return $result->compiled;
    }

    /**
     * @phpstan-impure Resolve for preview/publication without discarding conflict provenance.
     */
    public function resolve(BundleManifest $manifest): CompiledComposition|ProfileConflict
    {
        $input = $this->catalog->load($manifest);
        $sources = [
            ['dependency'=>new \AgenToolsPlatform\Core\Profiles\Dependency($manifest->profile, $manifest->provenance), 'value'=>$manifest->profile->toArray()]];
        if ($input->tenant->value !== $manifest->bundle->ref->tenant->value) {
            return new \AgenToolsPlatform\Core\Profiles\Composition\ProfileConflict('OWNER_SCOPE_MISMATCH', 'owner', $sources, 'Catalog returned another tenant input', $this->json);
        }
        foreach ($input->layers as $layer) {
            $expected = match ($layer->role) { LayerRole::Profile=>$manifest->profile, LayerRole::Definition=>$manifest->definition, default=>null };
            if ($expected !== null && $expected->toArray() !== $layer->dependency->ref->toArray()) {
                return new \AgenToolsPlatform\Core\Profiles\Composition\ProfileConflict('PINNED_ROOT_MISMATCH', 'dependencies', array_merge($sources,
                    [['dependency'=>$layer->dependency, 'value'=>$layer->dependency->ref->toArray()]]), 'Catalog returned mismatched pinned root owner/version/hash', $this->json);
            }
        }
        try { $actual = $input->dependencies(); }
        catch (IntegrityConflict) { return new \AgenToolsPlatform\Core\Profiles\Composition\ProfileConflict('DEPENDENCY_HASH_CONFLICT', 'dependencies', $sources, 'Conflicting dependency identity', $this->json); }
        $wanted = []; $loaded = [];
        foreach ($manifest->dependencies as $dependency) {
            if (isset($wanted[$dependency->ref->key()])) {
                return new \AgenToolsPlatform\Core\Profiles\Composition\ProfileConflict('DEPENDENCY_MANIFEST_MISMATCH', 'dependencies', $sources, 'Duplicate dependency in bundle manifest', $this->json);
            }
            $wanted[$dependency->ref->key()] = $dependency->toArray();
        }
        foreach ($actual as $dependency) { $loaded[$dependency->ref->key()] = $dependency->toArray(); }
        ksort($wanted, SORT_STRING); ksort($loaded, SORT_STRING);
        if ($wanted !== $loaded) {
            return new \AgenToolsPlatform\Core\Profiles\Composition\ProfileConflict('DEPENDENCY_MANIFEST_MISMATCH', 'dependencies', $sources, 'Bundle must pin the complete exact compiler input dependency closure', $this->json);
        }
        return $this->composer->compile($input);
    }

    /**
     * @phpstan-impure A pin is reconstructed from exact versions, without silently substituting latest.
     */
    public function pinned(BundleManifest $manifest): CompiledComposition|ProfileConflict
    {
        $sources = [['dependency'=>new \AgenToolsPlatform\Core\Profiles\Dependency($manifest->profile, $manifest->provenance), 'value'=>$manifest->compiler->toArray()]];
        if ($manifest->compiler->toArray() !== $this->revision->toArray()) {
            return new \AgenToolsPlatform\Core\Profiles\Composition\ProfileConflict('COMPILER_MISMATCH', 'compiler', $sources, 'Pinned compiler version/hash must be installed', $this->json);
        }
        $result = $this->resolve($manifest);
        if ($result instanceof ProfileConflict) { return $result; }
        if ($result->compiled->effectiveJson !== $manifest->compiled->effectiveJson || $result->compiled->restrictionsJson !== $manifest->compiled->restrictionsJson) {
            return new \AgenToolsPlatform\Core\Profiles\Composition\ProfileConflict('PINNED_OUTPUT_MISMATCH', 'compiled', $sources, 'Reconstructed typed output differs from the immutable published bytes', $this->json);
        }
        return $result;
    }
}
