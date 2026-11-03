<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Models\{Deployment, Support};
use AgenToolsPlatform\Core\Models\Routing\{Health, HostDecision, QualifiedTransport, Reason};
use AgenToolsPlatform\Core\Models\Qualification\Requirements;
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Qualification\Fixture as Q;
use AgenToolsPlatform\Tests\Routing\Fixture as F;

H::run([
    'ECO-EVAL-004'=>static function (): void {
        $q = new Q(); $q->snapshots = [$q->snapshot()]; $entry = $q->f->entry();
        $router = $q->router(); H::check($router->route($q->f->request(),$q->f->now())->selected !== null);
        $transport = new QualifiedTransport($q->f,$router,$q->f->request(),$entry,$q->f,true);
        $q->f->rules = [F::rule('deployment',$entry->deployment->ref->value)];
        H::rejects(static fn()=>$transport->generate($q->f->modelRequest(),$entry->deployment),ContractViolation::class,ErrorCode::AccessDenied);
        H::check($q->f->calls === 0 && $q->snapshots[0]->ref->value === 'old');
    },
    'qualification.health-does-not-grant'=>static function (): void {
        $q = new Q(); H::check($q->router()->route($q->f->request(),$q->f->now())->selected === null);
        $q->snapshots = [$q->snapshot()]; H::check($q->router()->route($q->f->request(),$q->f->now())->selected !== null);
        $q->f->states = [$q->f->qualified($q->f->entry(),changes:['health'=>Health::Unknown])];
        H::check($q->router()->route($q->f->request(),$q->f->now())->selected === null);
    },
    'qualification.current-policy-and-revoke'=>static function (): void {
        $q = new Q(); $q->snapshots = [$q->snapshot()]; $router = $q->router();
        $q->requirements = new Requirements($q->requirements->profile,$q->requirements->bundleHash,F::digest('f'),$q->policy());
        H::check($router->route($q->f->request(),$q->f->now())->selected === null);
        $q->requirements = new Requirements($q->requirements->profile,$q->requirements->bundleHash,F::digest('c'),$q->policy());
        $q->revoked = true; H::check($router->route($q->f->request(),$q->f->now())->selected === null);
        $q->revoked = false; $q->f->host = new HostDecision(Support::Supported,Support::Unknown,Support::Supported);
        H::check($router->route($q->f->request(),$q->f->now())->selected === null);
    },
    'V2ALL-EVAL-FAULT'=>static function (): void {
        $q = new Q(); $green = $q->snapshot();
        $red = $q->snapshot('reject',scorers:['technical.hard'=>'fail'],issued:'2026-11-02T00:00:00Z');
        foreach ([[$green,$red],[$red,$green]] as $snapshots) {
            $q->snapshots = $snapshots; H::check($q->router()->route($q->f->request(),$q->f->now())->selected === null);
        }
        $q->snapshots = [$green,$q->snapshot('expired',issued:'2026-11-01T01:00:00Z',expires:'2026-11-02T00:00:00Z')];
        H::check($q->router()->route($q->f->request(),$q->f->now())->selected === null);
        H::check($q->f->calls === 0 && $green->evidence->sourceHash->value === F::digest('d')->value);
    },
]);
