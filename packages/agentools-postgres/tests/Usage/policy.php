<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/Proof.php';

use AgenToolsPlatform\Core\Budget\{AdmissionScope,BudgetBoundary,Exposure,ReservationCheckpoint,ReservationStatus};
use AgenToolsPlatform\Postgres\Budget\OwnerResolution;
use AgenToolsPlatform\Postgres\Usage\{BacklogPolicy,ExportStatus};
use AgenToolsPlatform\Postgres\Tests\Usage\Proof;
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Usage\{Fixture as F,Owner};

Proof::run([
    'V2ALL-ADMISSION-NEGATIVE'=>static function (): void {
        $f=new F(); $o=new Owner(); $b=new BudgetBoundary($o,$o,$f->hasher); $s=AdmissionScope::fromArray(F::read('admission-request'));
        $unknown=new ReservationCheckpoint($s->request($f->hasher),F::ref('reservation-1'),ReservationStatus::Unknown,Exposure::Unknown);
        H::rejects(fn ()=>$b->release($unknown,F::now()),LogicException::class);
        H::rejects(fn ()=>$b->settle($unknown,$f->event([],['certainty'=>'unknown']),F::now()),LogicException::class);
        H::check($o->releases===0 && $o->settlements===0);
    },
    'V2ALL-ADMISSION-FAULT'=>static function (): void {
        $f=new F(); $o=new Owner(); $o->lost=true; $b=new BudgetBoundary($o,$o,$f->hasher); $s=AdmissionScope::fromArray(F::read('admission-request'));
        $active=new ReservationCheckpoint($s->request($f->hasher),F::ref('reservation-1'),ReservationStatus::Active,Exposure::Known);
        $unknown=$b->settle($active,$f->event([],['certainty'=>'final']),F::now());
        H::check($unknown->status===ReservationStatus::Unknown && $unknown->exposure===Exposure::Known);
        H::rejects(fn ()=>$b->release($unknown,F::now()),LogicException::class); H::check($o->releases===0);
    },
    'accounting.explicit-owner-proof'=>static function (): void {
        H::rejects(fn ()=>new OwnerResolution(F::ref('op'),'hash','applied'),InvalidArgumentException::class);
        H::rejects(fn ()=>new ExportStatus('exported','hash'),InvalidArgumentException::class);
        H::rejects(fn ()=>new BacklogPolicy(0,1,1),InvalidArgumentException::class);
        H::check((new OwnerResolution(F::ref('op'),'hash','unknown'))->proof===null);
        $json='{"plan":{"tools":{},"array":[]},"purpose":"test"}';
        $content=\AgenToolsPlatform\Postgres\Budget\RequestCodec::decode($json);
        $f=new F(); H::check($f->hasher->hash(F::ref('op')->tenant,'test',$content)->value
            ===$f->hasher->hash(F::ref('op')->tenant,'test',json_decode($json,flags:JSON_THROW_ON_ERROR))->value);
        $path=dirname(__DIR__,4).'/fixtures/platform/v1/admission/backlog-policy.json';
        $p=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
        H::check($p['production_enabled']===false && $p['owner_policy_required']===true);
        new BacklogPolicy($p['max_pending_facts_and_reservations'],$p['max_oldest_export_age_seconds'],$p['max_attempts'],$p['lease_seconds'],$p['retry_seconds']);
    },
]);
