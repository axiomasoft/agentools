<?php

declare(strict_types=1);

require_once __DIR__.'/merge-bootstrap.php';

use AgenToolsPlatform\Core\Profiles\{OwnerScope, UseBoundary};
use AgenToolsPlatform\Core\Profiles\Composition\{CompiledComposition, DeploymentPreference, LayerRole, MaterialChanges, OperationalOverrides, OperationalRole};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Profiles\Merge\{Fixture as F, Proof as P};

H::run([
    'CM-004'=>static function (): void {
        $f = new F(); $m = $f->bundle(); $pin = P::compiled($f->compiler->pinned($m)); $before = $pin->compiled->effectiveJson;
        $result = $f->composer->prepare($pin, $f->operational(OperationalRole::Host),
            $f->operational(OperationalRole::Session, preference:new DeploymentPreference('deployment.a', true)),
            $f->operational(OperationalRole::Live, $f->hard(denied:new \AgenToolsPlatform\Core\Profiles\Composition\Denials(deployments:['deployment.a']))), UseBoundary::Invocation);
        $calls = 0; if ($result instanceof CompiledComposition) { ++$calls; }
        H::check(P::conflict($result)->code === 'DEPLOYMENT_PREFERENCE_BLOCKED' && $calls === 0);
        H::check($pin->compiled->effectiveJson === $before && $m->bundle->version === 7);
    },
    'V2ALL-PROFILE-FAULT'=>static function (): void {
        $f = new F(); $seven = $f->bundle(); $pin = P::compiled($f->compiler->pinned($seven)); $hash = $seven->bundle->hash->value;
        $eight = $f->bundle(version:8);
        H::check($f->compiler->compile($seven)->effectiveJson !== $f->compiler->compile($eight)->effectiveJson);
        H::check(P::compiled($f->compiler->pinned($seven))->compiled->effectiveJson === $pin->compiled->effectiveJson);
        $f->catalog->corrupted = $f->input(version:'8');
        H::check(P::conflict($f->compiler->pinned($seven))->code === 'PINNED_ROOT_MISMATCH');
        $f->catalog->corrupted = null;
        foreach ([UseBoundary::Invocation, UseBoundary::Action, UseBoundary::Disclosure] as $boundary) {
            $blocked = $f->composer->prepare($pin, $f->operational(OperationalRole::Host), $f->operational(OperationalRole::Session),
                $f->operational(OperationalRole::Live, $f->hard(deployments:[])), $boundary);
            H::check(P::conflict($blocked)->code === 'DEPLOYMENT_INTERSECTION_EMPTY');
        }
        H::check($seven->bundle->hash->value === $hash && $seven->bundle->version === 7);
    },
    'merge.material-rights-current'=>static function (): void {
        $f = new F(); $a = $f->material('material.a'); $b = $f->material('material.b');
        $input = $f->input([
            LayerRole::Platform->value=>$f->layer(LayerRole::Platform, $f->hard(regions:['RU']), materials:new MaterialChanges([$a,$b])),
            LayerRole::Tenant->value=>$f->layer(LayerRole::Tenant, materials:new MaterialChanges(exclude:[$b])),
        ], [$f->layer(LayerRole::Lesson, materials:new MaterialChanges([$b]))]);
        $pin = P::compiled($f->composer->compile($input));
        H::check(count($pin->materials) === 1 && $pin->materials[0]->ref->toArray() === $a->ref->toArray());
        H::check($f->materials->calls === ['new_session:material.a']);
        P::compiled($f->composer->prepare($pin, $f->operational(OperationalRole::Host), $f->operational(OperationalRole::Session), $f->operational(OperationalRole::Live), UseBoundary::Invocation));
        $f->materials->denied = true;
        $denied = $f->composer->prepare($pin, $f->operational(OperationalRole::Host), $f->operational(OperationalRole::Session), $f->operational(OperationalRole::Live), UseBoundary::Disclosure);
        H::check(P::conflict($denied)->code === 'MATERIAL_ACCESS_DENIED');
        H::check($f->materials->calls === ['new_session:material.a','invocation:material.a','disclosure:material.a']);
        H::rejects(static fn () => new MaterialChanges([$a], [$a]), InvalidArgumentException::class);
    },
    'merge.no-unknown-default'=>static function (): void {
        $f = new F(); $input = $f->input([LayerRole::Profile->value=>$f->layer(LayerRole::Profile, version:'7', materials:new MaterialChanges([$f->material('material.a')]))]);
        $pin = P::compiled($f->composer->compile($input)); $f->materials->unknown = true;
        H::rejects(static fn () => $f->composer->prepare($pin, $f->operational(OperationalRole::Host), $f->operational(OperationalRole::Session), $f->operational(OperationalRole::Live), UseBoundary::Disclosure), RuntimeException::class);
        $f->materials->unknown = false;
        H::check(P::conflict($f->composer->prepare($pin, $f->operational(OperationalRole::Host, $f->hard(budget:0)),
            $f->operational(OperationalRole::Session), $f->operational(OperationalRole::Live), UseBoundary::Invocation))->code === 'BUDGET_EXHAUSTED');
        H::rejects(static fn () => (new ReflectionMethod($f, 'hard'))->invokeArgs($f, ['budget'=>null]), TypeError::class);
    },
    'merge.operational-role-required'=>static function (): void {
        $f = new F(); $pin = P::compiled($f->composer->compile($f->input()));
        H::check(P::conflict($f->composer->prepare($pin, $f->operational(OperationalRole::Live), $f->operational(OperationalRole::Session), $f->operational(OperationalRole::Host), UseBoundary::Invocation))->code === 'OPERATIONAL_ROLES_INVALID');
        $beta = new F('tenant-b');
        H::check(P::conflict($f->composer->prepare($pin, $beta->operational(OperationalRole::Host), $f->operational(OperationalRole::Session), $f->operational(OperationalRole::Live), UseBoundary::Action))->code === 'OWNER_SCOPE_MISMATCH');
        $relabelled = new OperationalOverrides(OwnerScope::tenant($f->tenant), 'policy.tenant', '1', OperationalRole::Live, $f->hard(), F::provenance(), $f->json);
        H::check(P::conflict($f->composer->prepare($pin, $f->operational(OperationalRole::Host), $f->operational(OperationalRole::Session), $relabelled, UseBoundary::Invocation))->code === 'DEPENDENCY_HASH_CONFLICT');
    },
    'merge.filtered-serialization'=>static function (): void {
        $f = new F(); $pin = P::compiled($f->composer->compile($f->input()));
        H::check(count($pin->input()->registry->tools) === 2);
        H::check(!str_contains(json_encode($pin, JSON_THROW_ON_ERROR), 'send_only'));
        $result = P::compiled($f->composer->prepare($pin, $f->operational(OperationalRole::Host),
            $f->operational(OperationalRole::Session, preference:new DeploymentPreference('deployment.a', false)),
            $f->operational(OperationalRole::Live, $f->hard(deployments:['deployment.b'])), UseBoundary::Action));
        H::check(P::effective($result)['selected_deployment'] === null && P::restrictions($result)['deployments'] === ['deployment.b']);
    },
], 'contract-scripted', 'contract-scripted');
