<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use AgenToolsPlatform\Conformance\Providers\Proof;
use AgenToolsPlatform\Core\Usage\{Producer,UsageEvent,UsageFold,UsageNormalizer};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Usage\Fixture as F;

Proof::run([
 'cost.fallback-resource-facts-business-ref'=>static function (): void {
    $f=new F(); $a=$f->event([],['certainty'=>'unknown','event_id'=>'inv-a-v1','business_operation_ref'=>'host-goal'],'inv-a');
    $b=$f->event(['input_total'=>100,'cached_subset'=>40,'output'=>12],['certainty'=>'final','event_id'=>'inv-b-v1','business_operation_ref'=>'host-goal'],'inv-b');
    $fold=UsageFold::fold([$a,$b,$a]); H::check(count($fold->active)===2 && count($fold->history)===2);
    H::check($a->units->toArray()['request_count']===1 && $a->units->toArray()['output_tokens']===null && $a->toArray()['provider_amount']===null);
    H::check($a->toArray()['business_operation_ref']===$b->toArray()['business_operation_ref'] && $b->units->toArray()['input_uncached_tokens']===60);
 },
 'cost.unknown-correction-replaces-once'=>static function (): void {
    $f=new F(); $a=$f->event([],['certainty'=>'unknown']); $d=$a->toArray(); $d['source']['usage_version']=2; $d['event_id']='resolved-v2';
    $d['certainty']='final'; $d['supersedes_event_id']=$a->id->value; $d['units']['output_tokens']=0;
    $b=UsageEvent::fromArray($d,$f->hasher); $fold=UsageFold::fold([$a,$b,$b]);
    H::check(count($fold->history)===2 && count($fold->active)===1 && array_values($fold->active)[0]->units->toArray()['output_tokens']===0);
    H::check(array_values($fold->active)[0]->toArray()['provider_amount']===null);
 },
 'cost.sdk-observer-is-telemetry-only'=>static function (): void {
    $f=new F(); $m=F::read('metadata');
    $observed=(new UsageNormalizer($f->hasher))->observe($m,'inv-1',1,Producer::Transport,Producer::SdkHook,F::mapping(),['output'=>12],true);
    H::check($observed->event===null && $observed->telemetryOnly);
 },
 'cost.cache-miss-does-not-bypass-budget'=>static function (): void {
    require_once dirname(__DIR__,3).'/packages/agentools-model-adapter/tests/Cache/bootstrap.php';
    $f=new \AgenToolsPlatform\ModelAdapter\Tests\Cache\Fixture(); $f->host->budget=false;
    H::rejects(fn ()=>$f->manager->resolve($f->prepared($f->key()),$f->now),LogicException::class);
    H::check($f->host->rebuilds===1 && $f->host->admissions===1 && $f->provider->creates===0);
 },
]);
