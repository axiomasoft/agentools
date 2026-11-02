<?php

declare(strict_types=1);
require_once __DIR__.'/Owner.php';
use AgenToolsPlatform\Core\Budget\{AdmissionConflict, AdmissionResult, AdmissionScope, BudgetBoundary, Exposure, ReservationCheckpoint, ReservationStatus};
use AgenToolsPlatform\Core\Common\{TenantId, UtcInstant};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Usage\{Fixture as F, Owner};

return [
 'budget.strict-scope'=>static function (): void {
    $f = new F(); $data = F::read('admission-request'); $s = AdmissionScope::fromArray($data);
    H::check($s->request($f->hasher)->hash->value === AdmissionScope::fromArray($data)->request($f->hasher)->hash->value);
    foreach (['purpose','subject_ref','business_operation_ref','policy_versions','pricing_version','estimated_units'] as $field) {
        $bad = $data; unset($bad[$field]); H::rejects(static fn ()=>AdmissionScope::fromArray($bad),InvalidArgumentException::class);
    }
 },
 'budget.identity-conflict'=>static function (): void {
    $f = new F(); $o = new Owner(); $b = new BudgetBoundary($o,$o,$f->hasher); $s = AdmissionScope::fromArray(F::read('admission-request'));
    $first = $b->admit($s,F::now()); $again = $b->admit($s,F::now());
    H::check($first->status === 'admitted' && $again->status === 'admitted' && $o->admissions === 1);
    foreach (['purpose'=>'changed','pricing_version'=>'changed','deployment_ref'=>'changed'] as $key=>$value) {
        $changed = AdmissionScope::fromArray(array_replace(F::read('admission-request'),[$key=>$value]));
        H::rejects(static fn ()=>$b->admit($changed,F::now()),AdmissionConflict::class);
    }
    $changed = AdmissionScope::fromArray(array_replace(F::read('admission-request'),['estimated_amount'=>['kind'=>'estimate','amount'=>'0.0100','currency'=>'XXX']]));
    H::rejects(static fn ()=>$b->admit($changed,F::now()),AdmissionConflict::class);
 },
 'budget.denied-unavailable-unknown'=>static function (): void {
    $f = new F(); $s = AdmissionScope::fromArray(F::read('admission-request'));
    foreach (['denied','unavailable','unknown'] as $status) {
        $o = new Owner(); $o->status = $status; $reply = (new BudgetBoundary($o,$o,$f->hasher))->admit($s,F::now());
        H::check($reply->status === $status && $o->admissions === 0);
    }
    $o = new Owner(); $o->lost = true; H::check((new BudgetBoundary($o,$o,$f->hasher))->admit($s,F::now())->status === 'unknown' && $o->admissions === 0);
 },
 'budget.current-guards'=>static function (): void {
    $f = new F(); $o = new Owner(); $b = new BudgetBoundary($o,$o,$f->hasher); $s = AdmissionScope::fromArray(F::read('admission-request'));
    H::check($b->admit($s,F::now())->status === 'admitted'); $o->denied = true;
    H::rejects(static fn ()=>$b->admit($s,F::now()),LogicException::class); H::check($o->admissions === 1);
 },
 'budget.release-exposure'=>static function (): void {
    $f = new F(); $o = new Owner(); $b = new BudgetBoundary($o,$o,$f->hasher); $req = AdmissionScope::fromArray(F::read('admission-request'))->request($f->hasher);
    foreach ([Exposure::Unknown,Exposure::Known] as $exposure) {
        $saved = new ReservationCheckpoint($req,F::ref('reservation-1'),ReservationStatus::Active,$exposure);
        H::rejects(static fn ()=>$b->release($saved,F::now()),LogicException::class);
    }
    $saved = new ReservationCheckpoint($req,F::ref('reservation-1'),ReservationStatus::Active,Exposure::NotSent,F::ref('not-sent-proof'));
    H::check($b->release($saved,F::now())->status === ReservationStatus::Released && $o->releases === 1);
    $o->lost = true; $unknown = $b->release($saved,F::now()); H::check($unknown->status === ReservationStatus::Unknown);
    H::rejects(static fn ()=>$b->release($unknown,F::now()),LogicException::class);
 },
 'budget.unknown-settlement'=>static function (): void {
    $f = new F(); $o = new Owner(); $b = new BudgetBoundary($o,$o,$f->hasher); $req = AdmissionScope::fromArray(F::read('admission-request'))->request($f->hasher);
    $saved = new ReservationCheckpoint($req,F::ref('reservation-1'),ReservationStatus::Active,Exposure::Known);
    foreach (['unknown','partial','preliminary'] as $certainty) { H::rejects(static fn ()=>$b->settle($saved,$f->event([],['certainty'=>$certainty]),F::now()),LogicException::class); }
    H::rejects(static fn ()=>$b->settle($saved,$f->event(['output'=>12],invocation:'inv-2'),F::now()),LogicException::class);
    H::rejects(static fn ()=>$b->settle($saved,$f->event(['output'=>12],['business_operation_ref'=>'other']),F::now()),LogicException::class);
    H::check($o->settlements === 0);
    $settled = $b->settle($saved,$f->event(['output'=>12]),F::now());
    H::check($settled->status === ReservationStatus::Settled && $o->settlements === 1);
    $o->lost = true;
    $unknown = $b->settle($saved,$f->event(['output'=>12]),F::now()); H::check($unknown->status === ReservationStatus::Unknown);
    H::rejects(static fn ()=>$b->settle($unknown,$f->event(['output'=>12]),F::now()),LogicException::class);
    H::check($o->settlements === 2 && $o->releases === 0);
 },
 'budget.renew'=>static function (): void {
    $f = new F(); $o = new Owner(); $b = new BudgetBoundary($o,$o,$f->hasher); $req = AdmissionScope::fromArray(F::read('admission-request'))->request($f->hasher);
    $saved = new ReservationCheckpoint($req,F::ref('reservation-1'),ReservationStatus::Active,Exposure::Known);
    H::check($b->renew($saved,new UtcInstant('2026-11-02T11:00:00Z'),F::now())->status === 'admitted');
    H::check($b->renew($saved->next(ReservationStatus::Unknown),new UtcInstant('2026-11-02T11:00:00Z'),F::now())->status === 'unknown' && $o->renewals === 1);
 },
 'budget.result-codec'=>static function (): void {
    $data = F::read('admission-result'); $tenant = new TenantId('alpha'); $r = AdmissionResult::fromArray($tenant,$data);
    H::check($r->permits(F::now()) && !$r->permits(new UtcInstant('2026-11-02T11:00:00Z')) && !$r->permits(new UtcInstant('2026-11-02T11:00:00.000001Z')));
    foreach ([['status'=>'unavailable','reason'=>null],['status'=>'admitted','expires_at'=>null],['limits'=>['output'=>-1]]] as $change) {
        H::rejects(static fn ()=>AdmissionResult::fromArray($tenant,array_replace($data,$change)),InvalidArgumentException::class);
    }
 }
];
