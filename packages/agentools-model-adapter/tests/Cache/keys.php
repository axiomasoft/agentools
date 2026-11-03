<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use AgenToolsPlatform\Core\Models\Cache\{Capabilities,Economics,Key,Kind};
use AgenToolsPlatform\ModelAdapter\Tests\Cache\{Fixture as F,Proof};
use AgenToolsPlatform\Tests\Harness as H;

Proof::run([
 'CM-031'=>static function (): void {
    $f=new F(); $key=$f->key(); $d=F::document('key'); $keys=[$key->id->value];
    foreach (['provider','deployment_ref','model_revision','api_mode','credential_ref','project_scope','region','tenant_ref','security_domain','security_version',
        'renderer_version','serialization_version'] as $field) { $keys[]=$f->key([$field=>$d[$field].'-other'])->id->value; }
    foreach (['profile_hash','skills_hash','tools_hash','materials_hash','release_hash'] as $field) { $keys[]=$f->key([$field=>'sha-256:'.str_repeat('f',64)])->id->value; }
    H::check(count(array_unique($keys))===count($keys)); $reverse=array_reverse($d,true); H::check(Key::fromArray($reverse,$f->hasher)->id->value===$key->id->value);
    $f->store->put($f->descriptor()); H::check($f->store->get($f->key(['tenant_ref'=>'other']))===null);
    foreach (F::document('scenarios')['scope_mismatches'] as $scenario) { H::check($f->store->get($f->key([$scenario['field']=>$scenario['value']]))===null); }
 },
 'cache.closed-key-and-kind'=>static function (): void {
    $f=new F(); H::rejects(fn ()=>$f->key(['run_id'=>'random-run']),InvalidArgumentException::class);
    $ids=[]; foreach (Kind::cases() as $kind) { $ids[]=$f->key(['kind'=>$kind->value])->id->value; } H::check(count(array_unique($ids))===6);
    H::check(!(new Capabilities())->allows('create',$f->now));
    H::rejects(fn ()=>$f->manager->resolve($f->prepared($f->key(['kind'=>Kind::Conversation->value])),$f->now),LogicException::class);
 },
 'cache.personal-retention'=>static function (): void {
    $f=new F(); H::rejects(fn ()=>$f->key(['personal'=>true]),InvalidArgumentException::class);
    $a=$f->key(['personal'=>true,'subject_ref'=>'child-a','retention_scope'=>'purpose-limited','retention_until'=>'2029-01-01T00:00:00Z']);
    $b=$f->key(['personal'=>true,'subject_ref'=>'child-b','retention_scope'=>'purpose-limited','retention_until'=>'2029-01-01T00:00:00Z']);
    H::check($a->id->value!==$b->id->value); H::rejects(fn ()=>$f->descriptor($a),InvalidArgumentException::class);
    $f->now=new \AgenToolsPlatform\Core\Common\UtcInstant('2030-01-01T00:00:00Z');
    H::rejects(fn ()=>$f->manager->resolve($f->prepared($a),$f->now),LogicException::class);
 },
 'CM-034'=>static function (): void {
    $r=Economics::evaluate(10,100,null,1,1000,500,'USD','v1','USD','v1'); H::check($r['status']==='unknown' && $r['net_micros']===null && !$r['guaranteed']);
    H::check(Economics::evaluate(10,100,5,1,1000,null,'USD','v1','USD','v1')['status']==='unknown');
    H::check(Economics::evaluate(10,100,5,1,1000,500,'USD','v1','EUR','v1')['status']==='unknown');
    H::check(Economics::evaluate(10,100,5,1,1000,500,'USD','v1','USD','v2')['status']==='unknown');
 },
 'cache.measured-roi'=>static function (): void {
    $d=F::document('economics'); $r=Economics::evaluate($d['uses'],$d['prefix_tokens'],$d['input_price_micros_per_token'],$d['cached_price_micros_per_token'],
        $d['warm_cost_micros'],$d['storage_cost_micros'],$d['currency'],$d['pricing_version'],$d['currency'],$d['pricing_version']);
    H::check($r['gross_micros']===$d['expected_gross_micros'] && $r['net_micros']===$d['expected_net_micros'] && !$r['guaranteed']);
    H::check(Economics::evaluate(1,1,0,0,0,0,'USD','v1','USD','v1')['status']==='break_even');
    H::check(Economics::evaluate(9007199254740991,9007199254740991,5,1,0,0,'USD','v1','USD','v1')['status']==='unknown');
 },
]);
