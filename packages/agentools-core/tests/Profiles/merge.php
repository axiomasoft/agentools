<?php

declare(strict_types=1);

require_once __DIR__.'/merge-bootstrap.php';

use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Core\Profiles\{BundleManifest, CompiledProfile, OwnerScope, UseBoundary};
use AgenToolsPlatform\Core\Profiles\Composition\{CompilationFailure, CompilationInput, ConfirmationFloor, Denials, EditableSettings,
    LayerRole, MethodologyComposition, MethodologyPack, MethodologySelection, OperationalRole, PolicyLayer,
    SafetyFloor, StyleOverride, Tone};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Profiles\Merge\{Fixture as F, Proof as P};

H::run([
    'CM-001'=>static function (): void {
        $f = new F(); $case = P::fixture('compatible.json'); $input = P::object($case['input']); $expected = P::object($case['expected']);
        $data = $f->input([
            LayerRole::Platform->value=>$f->layer(LayerRole::Platform, $f->hard(regions:P::strings($input['platform_regions']))),
            LayerRole::Tenant->value=>$f->layer(LayerRole::Tenant, $f->hard(regions:P::strings($input['tenant_regions']))),
            LayerRole::Profile->value=>$f->layer(LayerRole::Profile, $f->hard(tools:P::strings($input['profile_tools'])), '7'),
            LayerRole::Skill->value=>$f->layer(LayerRole::Skill, $f->hard(tools:P::strings($input['skill_tools']))),
        ]);
        $pin = P::compiled($f->composer->compile($data));
        $result = P::compiled($f->composer->prepare($pin,
            $f->operational(OperationalRole::Host, $f->hard(tools:P::strings($input['host_tools']))),
            $f->operational(OperationalRole::Session, $f->hard(tools:P::strings($input['session_tools']))),
            $f->operational(OperationalRole::Live, $f->hard(tools:P::strings($input['live_tools']))), UseBoundary::Invocation));
        H::check(P::restrictions($result)['regions'] === $expected['regions']);
        H::check(P::restrictions($result)['tools'] === $expected['tools']);
        $wire = json_encode($result, JSON_THROW_ON_ERROR);
        H::check(str_contains($wire, 'search_only') && !str_contains($wire, 'send_only'), 'Removed tool schema leaked');
    },
    'CM-002'=>static function (): void {
        $f = new F(); $case = P::fixture('conflict.json'); $input = P::object($case['input']); $expected = P::object($case['expected']);
        $result = $f->composer->compile($f->input([
            LayerRole::Platform->value=>$f->layer(LayerRole::Platform, $f->hard(regions:P::strings($input['platform_regions']))),
            LayerRole::Tenant->value=>$f->layer(LayerRole::Tenant, $f->hard(regions:P::strings($input['tenant_regions']))),
        ]));
        $network = 0; if ($result instanceof \AgenToolsPlatform\Core\Profiles\Composition\CompiledComposition) { ++$network; }
        $conflict = P::conflict($result);
        H::check($conflict->code === $expected['code'] && $conflict->field === $expected['field'] && $network === $expected['network_calls']);
        H::check(count($conflict->sourceRefs) >= 2 && count($conflict->sourceRefs) === count($conflict->sourceValuesHashes));
    },
    'V2ALL-PROFILE-POSITIVE'=>static function (): void {
        $f = new F();
        $input = $f->input([
            LayerRole::Platform->value=>$f->layer(LayerRole::Platform, $f->hard(regions:['RU'], confirmation:ConfirmationFloor::Required,
                safety:SafetyFloor::Strict, denied:new Denials(capabilities:['export']))),
            LayerRole::Tenant->value=>$f->layer(LayerRole::Tenant, $f->hard(budget:500, denied:new Denials(capabilities:['tracking']))),
        ]);
        $base = P::compiled($f->composer->compile($input));
        $result = P::compiled($f->composer->prepare($base,
            $f->operational(OperationalRole::Host, $f->hard(budget:80, confirmation:ConfirmationFloor::Fresh)),
            $f->operational(OperationalRole::Session, $f->hard(budget:60)),
            $f->operational(OperationalRole::Live, $f->hard(budget:40)), UseBoundary::Invocation));
        $r = P::restrictions($result);
        H::check($r['regions'] === ['RU'] && $r['tools'] === ['search'] && $r['budget_units'] === 40);
        H::check($r['confirmation_floor'] === 2 && $r['safety_floor'] === 1);
        H::check(P::object($r['forbidden'])['capabilities'] === ['export','tracking']);
        H::check(P::object(P::effective($result)['style'])['tone'] === 'friendly');
    },
    'V2ALL-PROFILE-NEGATIVE'=>static function (): void {
        $f = new F(); $beta = new F('tenant-b');
        H::check(P::conflict($f->composer->compile($f->input([LayerRole::Tenant->value=>$beta->layer(LayerRole::Tenant)])))->code === 'OWNER_SCOPE_MISMATCH');
        H::rejects(static fn () => (new ReflectionMethod(OwnerScope::class, 'tenant'))->invoke(null, null), TypeError::class);
        $ambiguous = $f->input(extra:[$f->layer(LayerRole::Profile, version:'8')]);
        H::check(P::conflict($f->composer->compile($ambiguous))->code === 'LAYER_ASSIGNMENT_AMBIGUOUS');
        H::check(P::conflict($f->composer->compile($f->input([LayerRole::Profile->value=>$f->layer(LayerRole::Profile, $f->hard(tools:['send']), '7')])))->code === 'TOOL_INTERSECTION_EMPTY');
        H::check(P::conflict($f->composer->compile($f->input([LayerRole::Tenant->value=>$f->layer(LayerRole::Tenant, $f->hard(denied:new Denials(regions:['RU'])))])))->code === 'REGION_INTERSECTION_EMPTY');
        $r = P::restrictions(P::compiled($f->composer->compile($f->input([LayerRole::Tenant->value=>$f->layer(LayerRole::Tenant,
            $f->hard(budget:9000, confirmation:ConfirmationFloor::None, safety:SafetyFloor::Baseline))]))));
        H::check($r['budget_units'] === 300 && $r['confirmation_floor'] === 1 && $r['safety_floor'] === 1);
    },
    'merge.methodology-approved'=>static function (): void {
        $f = new F(); $a = new MethodologyPack(OwnerScope::platform(), 'pack.a', '1', 'support-read', F::provenance(), $f->json);
        $b = new MethodologyPack(OwnerScope::platform(), 'pack.b', '1', 'support-read', F::provenance(), $f->json);
        $definition = $f->layer(LayerRole::Definition, compatibility:'support-read');
        $make = static fn (MethodologySelection $m): CompilationInput => $f->input([LayerRole::Definition->value=>$definition,
            LayerRole::Profile->value=>$f->layer(LayerRole::Profile, version:'7', methodology:$m)]);
        H::check(P::count(P::effective(P::compiled($f->composer->compile($make(new MethodologySelection([$a])))))['methodology']) === 1);
        H::check(P::conflict($f->composer->compile($make(new MethodologySelection([$a,$b]))))->code === 'METHODOLOGY_COMPOSITION_NOT_APPROVED');
        $manifest = new MethodologyComposition(OwnerScope::platform(), 'pack.approval', '1', 'support-read', [$a->dependency->ref,$b->dependency->ref], F::provenance(), $f->json);
        H::check(P::count(P::effective(P::compiled($f->composer->compile($make(new MethodologySelection([$a,$b], $manifest)))))['methodology']) === 2);
        H::check(P::conflict($f->composer->compile($make(new MethodologySelection([$b,$a], $manifest))))->code === 'METHODOLOGY_COMPOSITION_NOT_APPROVED');
        $wrong = new MethodologyPack(OwnerScope::platform(), 'pack.wrong', '1', 'other-domain', F::provenance(), $f->json);
        H::check(P::conflict($f->composer->compile($make(new MethodologySelection([$wrong]))))->code === 'METHODOLOGY_INCOMPATIBLE');
    },
    'merge.style-bounds'=>static function (): void {
        $f = new F();
        $result = $f->composer->compile($f->input([LayerRole::Definition->value=>$f->layer(LayerRole::Definition, editable:new EditableSettings(false, true, 8))]));
        H::check(P::conflict($result)->field === 'tone');
        $result = $f->composer->compile($f->input([
            LayerRole::Definition->value=>$f->layer(LayerRole::Definition, editable:new EditableSettings(true, true, 8)),
            LayerRole::Profile->value=>$f->layer(LayerRole::Profile, version:'7', style:new StyleOverride(displayName:'long-display-name'))]));
        H::check(P::conflict($result)->field === 'display_name');
        H::rejects(static fn () => new StyleOverride(displayName:"bad\nname"), InvalidArgumentException::class);
        H::rejects(static fn () => (new ReflectionClass(StyleOverride::class))->newInstanceArgs(['confirmationFloor'=>0]), Error::class);
        $result = $f->composer->compile($f->input(extra:[
            $f->layer(LayerRole::Lesson, style:new StyleOverride(Tone::Friendly), id:'lesson.a'),
            $f->layer(LayerRole::Lesson, style:new StyleOverride(Tone::Concise), id:'lesson.b')]));
        H::check(P::conflict($result)->code === 'STYLE_OVERRIDE_AMBIGUOUS');
    },
    'merge.required-capability'=>static function (): void {
        $f = new F();
        $denied = $f->layer(LayerRole::Tenant, $f->hard(denied:new Denials(capabilities:['read'])));
        H::check(P::conflict($f->composer->compile($f->input([LayerRole::Tenant->value=>$denied])))->code === 'REQUIRED_CAPABILITY_UNAVAILABLE');
        $tool = $f->layer(LayerRole::Tenant, $f->hard(required:['tool:send']));
        H::check(P::conflict($f->composer->compile($f->input([LayerRole::Tenant->value=>$tool])))->code === 'REQUIRED_CAPABILITY_UNAVAILABLE');
    },
    'merge.deterministic-provenance'=>static function (): void {
        $f = new F(); $bad = $f->layer(LayerRole::Tenant, $f->hard(regions:['EU'])); $input = $f->input([LayerRole::Tenant->value=>$bad]);
        $one = P::conflict($f->composer->compile($input));
        $two = P::conflict($f->composer->compile(new CompilationInput($f->tenant, array_reverse($input->layers), $input->registry, array_reverse(array_values($input->deployments)))));
        H::check($f->json->canonicalize($one->toArray()) === $f->json->canonicalize($two->toArray()));
        $hash = 'sha-256:'.hash('sha256', $f->json->canonicalize(['allowed'=>['EU'], 'forbidden'=>$bad->hard->denied->toArray(), 'required'=>['read']]));
        H::check(in_array($hash, $one->sourceValuesHashes, true));
        H::check(!str_contains(json_encode($one, JSON_THROW_ON_ERROR), '"allowed"'));
        $valid = $f->input(); $a = P::compiled($f->composer->compile($valid));
        $b = P::compiled($f->composer->compile(new CompilationInput($f->tenant, array_reverse($valid->layers), $valid->registry, array_reverse(array_values($valid->deployments)))));
        H::check($a->compiled->effectiveJson === $b->compiled->effectiveJson && $a->compiled->restrictionsJson === $b->compiled->restrictionsJson);
    },
    'merge.document-hash-validation'=>static function (): void {
        $f = new F();
        H::rejects(static fn () => new PolicyLayer(OwnerScope::tenant($f->tenant), 'profile.support', '7', LayerRole::Profile,
            $f->hard(), F::provenance(), $f->json, expectedHash:F::hash('wrong-cached-content')), IntegrityConflict::class);
        H::rejects(static fn () => $f->hard(regions:[null]), InvalidArgumentException::class);
        H::rejects(static fn () => $f->hard(budget:-1), InvalidArgumentException::class);
    },
    'merge.dependency-closure'=>static function (): void {
        $f = new F(); $m = $f->bundle();
        H::check($f->compiler->compile($m)->effectiveJson === $m->compiled->effectiveJson);
        $bad = new BundleManifest($m->bundle->ref, 7, $m->compiler, $m->definition, $m->profile, [], $m->compiled, $m->provenance, $f->json);
        H::check(P::conflict($f->compiler->resolve($bad))->code === 'DEPENDENCY_MANIFEST_MISMATCH');
        H::rejects(static fn () => $f->compiler->compile($bad), CompilationFailure::class);
        $changed = new BundleManifest($m->bundle->ref, 7, $m->compiler, $m->definition, $m->profile, $m->dependencies,
            new CompiledProfile('{"style":"forged"}', $m->compiled->restrictionsJson, $f->json), $m->provenance, $f->json);
        H::check(P::conflict($f->compiler->pinned($changed))->code === 'PINNED_OUTPUT_MISMATCH');
    },
], 'contract-scripted', 'contract-scripted');
