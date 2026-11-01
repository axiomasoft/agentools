<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use AgenToolsPlatform\Core\Common\{IntegrityConflict, Ref};
use AgenToolsPlatform\Core\Profiles\{BundleManifest, BundleValidator, CodeRegistry, CompiledProfile, DefinitionRef, Dependency,
    ManifestCodec, OwnerScope, Provenance, ResourceKind, ResourceRef};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Profiles\Fixture as F;

H::run([
    'profiles.typed-owner-registry' => static function (): void {
        $f = new F(); $m = $f->manifest();
        H::check((new BundleValidator($f->catalog, $f->compiler))->issues($m) === []);
        H::rejects(static fn () => new CodeRegistry([$f->dependencies[1]]), IntegrityConflict::class);
        H::rejects(static fn () => new CodeRegistry([$f->dependencies[0], $f->dependencies[0]]), IntegrityConflict::class);
        H::rejects(static fn () => new BundleManifest($m->bundle->ref, 1, $m->compiler, $m->profile, $m->definition,
            $m->dependencies, $m->compiled, $m->provenance, $f->json), InvalidArgumentException::class);
    },
    'profiles.content-hash-codec' => static function (): void {
        $f = new F(); $m = $f->manifest(); $codec = new ManifestCodec($f->json);
        H::check($codec->encode($codec->decode($codec->encode($m), $f->tenant)) === $codec->encode($m));
        $changed = $f->manifest(compiled: new CompiledProfile('{"tone":"hostile"}', $m->compiled->restrictionsJson, $f->json));
        H::check($changed->bundle->hash->value !== $m->bundle->hash->value);
        $wire = str_replace('neutral', 'hostile', $codec->encode($m));
        H::rejects(static fn () => $codec->decode($wire, $f->tenant), IntegrityConflict::class);
        H::rejects(static fn () => new CompiledProfile('[]', '{}', $f->json), InvalidArgumentException::class);
    },
    'profiles.compiler-output-pin' => static function (): void {
        $f = new F(); $v = new BundleValidator($f->catalog, $f->compiler);
        H::check($v->issues($f->manifest(compiler: new DefinitionRef('profile.compiler', '2', F::hash('other')))) === ['COMPILER_MISMATCH']);
        H::check($v->issues($f->manifest(compiled: new CompiledProfile('{"tone":"hostile"}', '{}', $f->json))) === ['COMPILER_OUTPUT_MISMATCH']);
        $broken = new class implements AgenToolsPlatform\Core\Profiles\BundleCompiler {
            public function version(): DefinitionRef { return (new F())->compiler->version(); }
            public function compile(BundleManifest $manifest): CompiledProfile { throw new IntegrityConflict('Transitive hash conflict'); }
        };
        H::check((new BundleValidator($f->catalog, $broken))->issues($f->manifest()) === ['COMPILATION_FAILED']);
    },
    'profiles.dependency-hash-provenance' => static function (): void {
        $f = new F(); $v = new BundleValidator($f->catalog, $f->compiler); $deps = $f->dependencies;
        $ref = $deps[1]->ref;
        $deps[1] = new Dependency(new ResourceRef($ref->owner, $ref->kind, $ref->version->id, $ref->version->version, F::hash('wrong')), F::provenance());
        H::check($v->issues($f->manifest(dependencies: $deps)) === ['DEPENDENCY_INVALID']);
        $deps = $f->dependencies; $deps[1] = new Dependency($ref, new Provenance('actor:forged', 'validation:wrong', 'evaluation:wrong'));
        H::check($v->issues($f->manifest(dependencies: $deps)) === ['DEPENDENCY_INVALID']);
        H::rejects(static fn () => new Provenance('', 'v', 'e'), InvalidArgumentException::class);
    },
    'profiles.cross-tenant-overlay' => static function (): void {
        $f = new F(); $other = new F('tenant-b');
        $deps = $f->dependencies; $deps[1] = $other->dependencies[1];
        H::check((new BundleValidator($f->catalog, $f->compiler))->issues($f->manifest(dependencies: $deps)) === ['DEPENDENCY_INVALID']);
        $spoof = new ResourceRef(OwnerScope::platform(), ResourceKind::Deployment, 'deployment.read', '1', F::hash('deployment'));
        $before = $f->overlay->calls;
        H::rejects(static fn () => $f->catalog->resolve($spoof, $f->tenant), AgenToolsPlatform\Core\Common\ContractViolation::class);
        H::check($f->overlay->calls === $before, 'Platform miss must not fall through to a tenant overlay');
    },
    'profiles.closure-duplicate-missing' => static function (): void {
        $f = new F(); $v = new BundleValidator($f->catalog, $f->compiler);
        H::check($v->issues($f->manifest(dependencies: [...$f->dependencies, $f->dependencies[0]])) === ['DEPENDENCY_INVALID']);
        H::check($v->issues($f->manifest(dependencies: [$f->dependencies[0], $f->dependencies[1]])) === ['DEPENDENCY_INVALID']);
        H::check($v->issues($f->manifest(dependencies: [$f->dependencies[0], $f->dependencies[2]])) === ['COMPILATION_FAILED']);
    },
    'profiles.deterministic-immutable-bytes' => static function (): void {
        $f = new F(); $m = $f->manifest();
        H::check($f->manifest(dependencies: array_reverse($f->dependencies))->bundle->hash->value === $m->bundle->hash->value);
        $array = $m->toArray();
        $compiled = $array['compiled']; H::check(is_array($compiled));
        if (!is_array($compiled) || !$compiled['effective_fields'] instanceof stdClass) { throw new RuntimeException('Expected effective fields'); }
        $compiled['effective_fields']->tone = 'changed';
        H::check($m->compiled->effectiveJson === '{"tone":"neutral"}');
        H::rejects(static fn () => (new ReflectionProperty(BundleManifest::class, 'bundle'))->setValue($m, $f->manifest(8)->bundle), Error::class);
        H::check($f->manifest(8)->bundle->hash->value !== $m->bundle->hash->value);
    },
    'profiles.revoked-dependency-not-cached' => static function (): void {
        $f = new F(); $m = $f->manifest(); $v = new BundleValidator($f->catalog, $f->compiler);
        $v->assertDependencies($m, $f->tenant); $f->overlay->revoked = true;
        H::rejects(static fn () => $v->assertDependencies($m, $f->tenant), AgenToolsPlatform\Core\Common\ContractViolation::class);
        H::check(count($m->dependencies) === 3);
    },
    'profiles.strict-manifest-envelope' => static function (): void {
        $f = new F(); $codec = new ManifestCodec($f->json); $wire = $codec->encode($f->manifest());
        H::rejects(static fn () => $codec->decode('{"extra":1,' . substr($wire, 1), $f->tenant), InvalidArgumentException::class);
        H::rejects(static fn () => $codec->decode('{"format":"a",' . substr($wire, 1), $f->tenant), InvalidArgumentException::class);
        H::rejects(static fn () => $codec->decode(str_replace('"owner":{"kind":"tenant","tenant_id":"tenant-a"}', '"owner":{"kind":"platform"}', $wire), $f->tenant), InvalidArgumentException::class);
    },
], 'unit', 'unit');
