<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use AgenToolsPlatform\Core\Common\{IntegrityConflict, UtcInstant};
use AgenToolsPlatform\Core\Models\Qualification\{Measurements, RolloutPolicy};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Qualification\Fixture as Q;
use AgenToolsPlatform\Tests\Routing\Fixture as F;

H::run([
    'V2ALL-EVAL-POSITIVE'=>static function (): void {
        $q = new Q(); $old = $q->snapshot(); $new = $q->snapshot('new',$q->f->entry(['id'=>'canary'])); $policy = $q->rollout($old->ref,$new->ref);
        $measurement = $q->measurements($new); $session = F::ref('session');
        $a = $policy->choose($session,$old,$new,$measurement,0,$q->f->now(),$q->f->hasher);
        H::check($a->canary && $a->snapshot->value === 'new');
        H::check($policy->choose($session,$old,$new,$measurement,0,$q->f->now(),$q->f->hasher)->toArray() === $a->toArray());
        $bounded = $policy->choose(F::ref('next'),$old,$new,$measurement,1,$q->f->now(),$q->f->hasher);
        H::check(!$bounded->canary && $bounded->snapshot->value === 'old' && $bounded->reason === 'canary_admission_bound');
        $rollback = $q->rollout($old->ref,null,'rollback',rollbackOf:$policy->ref);
        H::check($rollback->choose(F::ref('after'),$old,null,null,0,$q->f->now(),$q->f->hasher)->snapshot->value === 'old');
        H::check($a->snapshot->value === 'new' && $q->f->calls === 0 && $q->f->ownerOperationCount() === 1);
    },
    'qualification.canary-measured-owner-bounds'=>static function (): void {
        $q = new Q(); $old = $q->snapshot(); $new = $q->snapshot('new',$q->f->entry(['id'=>'canary'])); $policy = $q->rollout($old->ref,$new->ref);
        foreach ([null,new Measurements(F::ref('unknown'),$policy->baseline,$policy->baselineHash,$new->tuple->hash($q->f->hasher),5,['latency_ms'=>null],0,new UtcInstant('2026-11-01T00:00:00Z'),new UtcInstant('2026-12-01T00:00:00Z')),
            new Measurements(F::ref('slow'),$policy->baseline,$policy->baselineHash,$new->tuple->hash($q->f->hasher),5,['latency_ms'=>201],0,new UtcInstant('2026-11-01T00:00:00Z'),new UtcInstant('2026-12-01T00:00:00Z'))] as $m) {
            H::check(!$policy->choose(F::ref('session'),$old,$new,$m,0,$q->f->now(),$q->f->hasher)->canary);
        }
        foreach (['baseline','tuple','samples','hard','expired','future'] as $fault) {
            $m = new Measurements(F::ref('measurement'),$policy->baseline,$fault === 'baseline' ? F::digest('f') : $policy->baselineHash,
                $fault === 'tuple' ? F::digest('f') : $new->tuple->hash($q->f->hasher),$fault === 'samples' ? 0 : 5,['latency_ms'=>100],
                $fault === 'hard' ? 1 : 0,new UtcInstant($fault === 'future' ? '2026-11-03T00:00:00Z' : '2026-11-01T00:00:00Z'),
                new UtcInstant($fault === 'expired' ? '2026-11-02T00:00:00Z' : '2026-12-01T00:00:00Z'));
            H::check(!$policy->measured($m,$new,$q->f->now(),$q->f->hasher),$fault);
        }
        H::rejects(static fn()=>new RolloutPolicy($policy->ref,$old->ref,$new->ref,10001,1,$policy->baseline,$policy->baselineHash,1,$policy->bounds,$policy->reason),InvalidArgumentException::class);
        H::rejects(static fn()=>new RolloutPolicy($policy->ref,$old->ref,$new->ref,5000,1,$policy->baseline,$policy->baselineHash,1,[],$policy->reason),InvalidArgumentException::class);
    },
    'qualification.stable-cohort-and-zero-admission'=>static function (): void {
        $q = new Q(); $old = $q->snapshot(); $new = $q->snapshot('new',$q->f->entry(['id'=>'canary'])); $p = $q->rollout($old->ref,$new->ref);
        $partial = new RolloutPolicy($p->ref,$old->ref,$new->ref,5000,2,$p->baseline,$p->baselineHash,1,$p->bounds,$p->reason);
        $inside = 0; $outside = 0;
        for ($i=0;$i<100;++$i) { $session = F::ref('session-'.$i); $first = $partial->inCohort($session,$q->f->hasher);
            H::check($first === $partial->inCohort($session,$q->f->hasher)); if ($first) { ++$inside; } else { ++$outside; } }
        H::check($inside > 0 && $outside > 0);
        H::rejects(static fn()=>$p->choose(F::ref('session'),$old,null,null,0,$q->f->now(),$q->f->hasher),IntegrityConflict::class);
    },
]);
