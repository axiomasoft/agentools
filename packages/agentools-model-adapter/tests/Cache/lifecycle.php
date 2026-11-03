<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use AgenToolsPlatform\ModelAdapter\Tests\Cache\{Fixture as F,Proof};
use AgenToolsPlatform\Tests\Harness as H;

Proof::run([
 'CM-032'=>static function (): void {
    $f=new F(); $f->store->put($f->descriptor()); $f->provider->useStatus=F::document('scenarios')['provider_miss']['status']; $f->host->overflow=true;
    H::rejects(fn ()=>$f->manager->resolve($f->prepared($f->key()),$f->now),LogicException::class); H::check($f->host->admissions===0 && $f->provider->creates===0);
    $f->host->overflow=false; $f->host->budget=false; H::rejects(fn ()=>$f->manager->resolve($f->prepared($f->key()),$f->now),LogicException::class);
    $f->host->budget=true; $f->host->forbiddenRoute=true; H::rejects(fn ()=>$f->manager->resolve($f->prepared($f->key()),$f->now),InvalidArgumentException::class);
 },
 'CM-033'=>static function (): void {
    $f=new F(); $f->store->put($f->descriptor()); $f->provider->enabled=['use']; $reply=$f->manager->revoke($f->key(),$f->now);
    H::check($reply->status===F::document('scenarios')['revoked_material']['expected_status'] && $reply->receipt===null && $reply->descriptor->expiresAt->value==='2030-01-01T00:00:00Z');
    H::check(!$f->store->get($f->key())->usable($f->now) && $f->provider->deletes===0);
    $f->host->revoked=true; H::rejects(fn ()=>$f->manager->resolve($f->prepared($f->key()),$f->now),LogicException::class);
 },
 'V2-P9-4-REVOKE-POS'=>static function (): void {
    $f=new F(); $f->store->put($f->descriptor());
    $f->provider->onDelete=fn ()=>H::check(!$f->store->get($f->key())->usable($f->now));
    $r=$f->manager->revoke($f->key(),$f->now); H::check($r->status==='deleted' && $r->receipt!==null && $f->provider->deletes===1);
    H::check($f->manager->revoke($f->key(),$f->now)->status==='deleted' && $f->provider->deletes===1);
 },
 'V2-P9-4-MISS-POS'=>static function (): void {
    $f=new F(); $r=$f->manager->resolve($f->prepared($f->key()),$f->now);
    H::check($r->hit===null && $r->reason==='miss' && $f->host->rebuilds===1 && $f->host->admissions===1 && $f->host->checks>=3);
    $f->store->put($f->descriptor(expiry:new \AgenToolsPlatform\Core\Common\UtcInstant('2021-01-01T00:00:00Z')));
    H::check($f->manager->resolve($f->prepared($f->key()),$f->now)->reason==='stale');
 },
 'cache.hit-current-acl-budget'=>static function (): void {
    $f=new F(); $f->store->put($f->descriptor()); H::check($f->manager->resolve($f->prepared($f->key()),$f->now)->hit!==null);
    H::check($f->host->admissions===1 && $f->host->rebuilds===0); $f->host->budget=false;
    H::rejects(fn ()=>$f->manager->resolve($f->prepared($f->key()),$f->now),LogicException::class);
    $f->host->budget=true; $f->host->storage=false;
    H::rejects(fn ()=>$f->manager->resolve($f->prepared($f->key()),$f->now),LogicException::class);
 },
 'cache.mid-use-revoke'=>static function (): void {
    $f=new F(); $f->store->put($f->descriptor());
    $f->host->onAdmit=fn ()=>$f->store->invalidate($f->key(),'owner_revoke',$f->now);
    H::rejects(fn ()=>$f->manager->resolve($f->prepared($f->key()),$f->now),LogicException::class);
 },
 'V2ALL-CACHE-FAULT'=>static function (): void {
    $f=new F(); $f->store->put($f->descriptor()); $f->provider->lostDelete=true;
    $r=$f->manager->revoke($f->key(),$f->now); H::check($r->status==='unknown' && !$f->store->get($f->key())->usable($f->now));
    H::check($f->manager->reconcileDeletion($f->now) && $f->provider->deletes===1 && $f->provider->lookups===[$r->id->value]);
    $f->provider->useStatus='unknown'; $r=$f->manager->resolve($f->prepared($f->key()),$f->now); H::check($r->hit===null && $r->reason==='revoked');
 },
]);
