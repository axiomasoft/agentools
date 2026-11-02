<?php

declare(strict_types=1);
use AgenToolsPlatform\Core\Usage\{Producer, UsageConflict, UsageEvent, UsageFold, UsageNormalizer, Values};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Usage\Fixture as F;

$tests = [];
foreach (['CM-037','ECO-USE-002'] as $id) {
    $tests[$id] = static function (): void {
        $f = new F(); $one = $f->event(['output'=>12]); $replay = UsageEvent::fromArray($one->toArray(),$f->hasher);
        $p = UsageFold::fold([$one,$replay,$one]); H::check(count($p->history) === 1 && count($p->active) === 1);
        H::check($one->acknowledgement() === $replay->acknowledgement() && $one->units->toArray()['request_count'] === 1);
    };
}
foreach (['CM-038','ECO-USE-007'] as $id) {
    $tests[$id] = static function (): void {
        $f = new F(); $n = new UsageNormalizer($f->hasher); $events = []; $telemetry = 0;
        foreach (Producer::cases() as $observer) {
            $seen = $n->observe(F::read('metadata'),'inv-1',1,Producer::Transport,$observer,F::mapping(),['output'=>12],true);
            if ($seen->telemetryOnly) { ++$telemetry; } if ($seen->event !== null) { $events[] = $seen->event; }
        }
        H::check(count($events) === 1 && $telemetry === 1);
    };
}
foreach (['ECO-USE-003','ECO-USE-009','V2-P9-2-FALLBACK-NEG'] as $id) {
    $tests[$id] = static function () use ($id): void {
        $f = new F(); $audio = $id === 'ECO-USE-009';
        $one = $f->event($audio ? ['audio_input'=>'1.250','audio_unit'=>'second'] : ['output'=>12]);
        $bad = $f->event($audio ? ['audio_input'=>'1.251','audio_unit'=>'second'] : ['output'=>13]);
        H::rejects(static fn ()=>UsageFold::fold([$one,$bad]),UsageConflict::class);
        $p = UsageFold::fold([$one]); H::check(count($p->history) === 1 && $p->history[0]->hash->value === $one->hash->value);
        H::check($audio ? $one->units->toArray()['audio_input_units'] === '1.250' : $one->units->toArray()['output_tokens'] === 12);
    };
}
$tests['ECO-USE-004'] = static function (): void {
    $f = new F(); $one = $f->event([],['certainty'=>'preliminary']);
    $two = $f->event(['output'=>12],['event_id'=>'usage-inv-1-v2','supersedes_event_id'=>$one->id->value],version:2);
    $p = UsageFold::fold([$two,$one,$two]); H::check(count($p->history) === 2 && count($p->active) === 1);
    H::check(array_values($p->active)[0]->units->toArray()['output_tokens'] === 12 && $one->units->toArray()['output_tokens'] === null);
};
$tests['V2-P9-2-FALLBACK-POS'] = static function (): void {
    $f = new F(); $one = $f->event([],['certainty'=>'unknown']);
    $two = $f->event(['output'=>7],['event_id'=>'usage-fallback-v1','deployment_ref'=>'alternate-fixture'],invocation:'inv-2');
    $p = UsageFold::fold([$one,$two]); H::check(count($p->active) === 2);
    H::check($one->toArray()['business_operation_ref'] === $two->toArray()['business_operation_ref'] && $one->invocation->value !== $two->invocation->value);
    H::check($one->units->toArray()['request_count'] === 1 && $two->units->toArray()['request_count'] === 1);
};
$tests['V2ALL-USAGE-POSITIVE'] = static function (): void {
    $f = new F(); $e = $f->event(['input_total'=>100,'cached_subset'=>40,'output'=>12,'reasoning'=>5,'audio_output'=>'0.125','audio_unit'=>'second',
        'amount'=>['amount'=>'0.0021','currency'=>'XXX']],['estimate'=>['kind'=>'estimate','amount'=>'0.0100','currency'=>'XXX'],'pricing_version'=>'synthetic/v1']);
    $u = $e->units->toArray(); H::check($u['input_uncached_tokens'] === 60 && $u['input_cache_read_tokens'] === 40 && $u['reasoning_tokens'] === 5);
    H::check($e->providerAmount?->amount === '0.0021' && $e->estimate?->amount === '0.0100' && $u['audio_output_units'] === '0.125');
    H::check(UsageEvent::fromArray($e->toArray(),$f->hasher)->hash->value === $e->hash->value);
};
$tests['V2ALL-USAGE-NEGATIVE'] = static function (): void {
    $f = new F(); $one = $f->event();
    foreach ([['tenant_ref'=>'beta'],['business_operation_ref'=>'other'],['source'=>array_replace(Values::object($one->toArray()['source']),['producer'=>'sdk_hook'])]] as $change) {
        $bad = UsageEvent::fromArray(array_replace($one->toArray(),$change),$f->hasher);
        if (isset($change['tenant_ref'])) { H::check(count(UsageFold::fold([$one,$bad])->active) === 2); }
        else { H::rejects(static fn ()=>UsageFold::fold([$one,$bad]),UsageConflict::class); }
    }
    H::rejects(static fn ()=>$f->event([],['estimate'=>['kind'=>'actual','amount'=>'0.1','currency'=>'XXX']]),InvalidArgumentException::class);
    $orphan = $f->event([],['event_id'=>'orphan','supersedes_event_id'=>'absent'],version:2);
    H::rejects(static fn ()=>UsageFold::fold([$orphan]),InvalidArgumentException::class);
};
$tests['V2ALL-USAGE-FAULT'] = static function (): void {
    $f = new F(); $lost = $f->event([],['certainty'=>'unknown']);
    $known = $f->event(['output'=>7],['certainty'=>'partial','event_id'=>'usage-inv-1-v2','supersedes_event_id'=>$lost->id->value],version:2);
    $p = UsageFold::fold([$known,$lost,$lost,$known]); H::check(count($p->history) === 2 && count($p->active) === 1);
    H::check($lost->providerAmount === null && $lost->units->toArray()['output_tokens'] === null && $known->units->toArray()['reasoning_tokens'] === null);
    H::check(array_values($p->active)[0]->units->toArray()['request_count'] === 1);
};
return $tests;
