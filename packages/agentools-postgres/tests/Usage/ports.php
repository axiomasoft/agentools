<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/Proof.php';

use AgenToolsPlatform\Core\Budget\{AdmissionScope,BudgetBoundary,Exposure,ReservationCheckpoint,ReservationStatus};
use AgenToolsPlatform\Core\Usage\UsageFold;
use AgenToolsPlatform\Postgres\Tests\Usage\Proof;
use AgenToolsPlatform\Tests\{Harness as H};
use AgenToolsPlatform\Tests\Usage\{Fixture as F,Owner};

function boundary(): array {
    $f=new F(); $owner=new Owner();
    return [$f,$owner,new BudgetBoundary($owner,$owner,$f->hasher),AdmissionScope::fromArray(F::read('admission-request'))];
}
Proof::run([
    'CM-039'=>static function (): void { [$f,$o,$b,$s]=boundary(); $o->status='unavailable'; H::check($b->admit($s,F::now())->status==='unavailable' && $o->admissions===0); },
    'ECO-ADM-001'=>static function (): void { [$f,$o,$b,$s]=boundary(); $o->status='denied'; $providerCalls=0; if ($b->admit($s,F::now())->status==='admitted') { ++$providerCalls; } H::check($providerCalls===0); },
    'ECO-ADM-002'=>static function (): void { [$f,$o,$b,$s]=boundary(); $o->status='unavailable'; H::check($b->admit($s,F::now())->status!=='admitted' && $o->admissions===0); },
    'ECO-ADM-004'=>static function (): void { $f=new F(); $a=$f->event(['input_total'=>100,'cache_read'=>40]); $b=$f->event(['output'=>12],['event_id'=>'usage-b'],'inv-b'); $p=UsageFold::fold([$a,$b]); H::check(count($p->active)===2 && $a->toArray()['business_operation_ref']===$b->toArray()['business_operation_ref']); },
    'ECO-ADM-005'=>static function (): void { [$f,$o,$b,$s]=boundary(); $o->denied=true; H::rejects(fn ()=>$b->admit($s,F::now()),LogicException::class); H::check($o->admissions===0); },
    'V2ALL-ADMISSION-POSITIVE'=>static function (): void {
        [$f,$o,$b,$s]=boundary(); $reply=$b->admit($s,F::now()); $event=$f->event([],['certainty'=>'final']);
        $saved=new ReservationCheckpoint($s->request($f->hasher),$reply->reservationRef,ReservationStatus::Active,Exposure::Known);
        H::check($b->settle($saved,$event,F::now())->status===ReservationStatus::Settled && $o->settlements===1 && $o->guards>=3);
    },
]);
