<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use AgenToolsPlatform\ModelAdapter\Tests\Cache\{Fixture as F,Proof};
use AgenToolsPlatform\Tests\Harness as H;

Proof::run([
 'V2ALL-CACHE-POSITIVE'=>static function (): void {
    $f=new F(); $request=$f->warm(); H::check($f->manager->warm($f->key(),$request,$f->policy(1),$f->now)!==null);
    H::check($f->manager->warm($f->key(),$request,$f->policy(1),$f->now)!==null && $f->provider->creates===1 && $f->budget->admissions===1);
    $e=array_values($f->sink->events)[0]; H::check($e->units->toArray()['input_uncached_tokens']===60 && $e->units->toArray()['input_cache_read_tokens']===40);
    H::check($e->toArray()['provider_amount']===['amount'=>'0.02','currency'=>'USD'] && $e->units->toArray()['request_count']===1);
    H::rejects(fn ()=>$f->manager->warm($f->key(['materials_hash'=>'sha-256:'.str_repeat('f',64)]),$f->warm('second'),$f->policy(1),$f->now),LogicException::class);
 },
 'V2ALL-CACHE-NEGATIVE'=>static function (): void {
    foreach (['warm','stable','storage'] as $field) {
        $f=new F(); $f->host->$field=false; H::rejects(fn ()=>$f->manager->warm($f->key(),$f->warm(),$f->policy(),$f->now),LogicException::class); H::check($f->provider->creates===0 && $f->budget->admissions===0);
    }
    $f=new F(); $f->provider->enabled=[]; H::rejects(fn ()=>$f->manager->warm($f->key(),$f->warm(),$f->policy(),$f->now),LogicException::class);
    $f->provider->enabled=['create']; $f->budget->outcome='unavailable';
    H::rejects(fn ()=>$f->manager->warm($f->key(),$f->warm(),$f->policy(),$f->now),LogicException::class); H::check($f->provider->creates===0);
    $e=array_values($f->sink->events)[0]; H::check($e->units->toArray()['request_count']===0 && $e->toArray()['provider_amount']===null);
    $f=new F(); $p=$f->policy();
    $unknownRoi=new \AgenToolsPlatform\Core\Models\Cache\WarmPolicy($p->requestedProfile,$p->windowStart,$p->windowEnd,2,100,2,60);
    H::rejects(fn ()=>$f->manager->warm($f->key(),$f->warm(),$unknownRoi,$f->now),LogicException::class); H::check($f->provider->creates===0);
    $f=new F(); $f->budget->afterAdmit=function () use ($f): void { $f->now=new \AgenToolsPlatform\Core\Common\UtcInstant('2030-01-02T00:00:00Z'); };
    H::rejects(fn ()=>$f->manager->warm($f->key(),$f->warm(),$f->policy(),$f->now),LogicException::class); H::check($f->provider->creates===0);
 },
 'cache.warm-unknown-no-release'=>static function (): void {
    $f=new F(); $f->provider->lostCreate=true; H::check($f->manager->warm($f->key(),$f->warm(),$f->policy(),$f->now)===null);
    $e=array_values($f->sink->events)[0]; H::check($e->certainty->value==='unknown' && $e->units->toArray()['input_uncached_tokens']===null && $e->toArray()['provider_amount']===null);
    H::check($f->manager->warm($f->key(),$f->warm(),$f->policy(),$f->now)===null && $f->provider->creates===1 && $f->budget->releases===0);
 },
 'cache.warm-accounting-replay'=>static function (): void {
    $f=new F(); $f->sink->lost=true; H::rejects(fn ()=>$f->manager->warm($f->key(),$f->warm(),$f->policy(),$f->now),RuntimeException::class);
    H::check(!$f->store->get($f->key())->usable($f->now)); $f->sink->lost=false;
    H::check($f->manager->reconcileWarm() && count($f->sink->events)===1 && $f->provider->creates===1 && $f->store->get($f->key())->usable($f->now));
 },
 'cache.refresh-and-idle-retire'=>static function (): void {
    $f=new F(); $f->store->put($f->descriptor());
    H::check($f->manager->warm($f->key(),$f->warm('refresh','refresh'),$f->policy(),$f->now)!==null && $f->provider->refreshes===1);
    H::check($f->manager->retireIdle(new \AgenToolsPlatform\Core\Common\UtcInstant('2029-01-01T00:00:00Z'),$f->now,1)===1 && $f->provider->deletes===1);
    H::check($f->manager->warm($f->key(),$f->warm('fresh'),$f->policy(),$f->now)!==null && $f->provider->creates===1);
 },
]);
