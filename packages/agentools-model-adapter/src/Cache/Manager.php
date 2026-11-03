<?php
declare(strict_types=1);
namespace AgenToolsPlatform\ModelAdapter\Cache;

use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Models\Cache\{Admission,Deletion,Descriptor,Guard,Key,Kind,Prepared,Provider,ProviderResult,Rebuilder,Resolution,Store,WarmPolicy};
use AgenToolsPlatform\Core\Models\Time;

/** Cache resource lifecycle only. The existing Runtime remains the invocation/effect loop. */
final readonly class Manager
{
    private \Closure $clock;
    /** @param (\Closure(): UtcInstant)|null $clock */
    public function __construct(private Store $store,private Guard $guard,private Provider $provider,
        private Rebuilder $rebuilder,private Admission $admission,private WarmAccounting $accounting,?\Closure $clock=null)
    { $this->clock=$clock ?? (new \AgenToolsPlatform\ModelAdapter\SystemClock())->now(...); }

    public function resolve(Prepared $current,UtcInstant $now): Resolution
    {
        $key=$current->key;
        if ($key->kind===Kind::Conversation) { throw new \LogicException('Provider conversation is not portable history or a prefix cache'); }
        $this->rights($key,$now); $descriptor=$this->store->get($key); $reason='miss'; $hit=false;
        if ($descriptor!==null) {
            $key->assertSame($descriptor->key);
            $reason=$descriptor->state==='invalidated'?'revoked':($descriptor->usable($now)?'hit':'stale');
            $hit=$descriptor->usable($now);
            if ($hit && $key->kind->external()) {
                if (!$this->provider->capabilities($key)->allows('use',($this->clock)())) { $hit=false; $reason='unsupported'; }
                else {
                    $this->store->assertIdle();
                    try { $reply=$this->provider->use($descriptor); $hit=$reply->status==='hit'; $reason=$hit?'hit':'provider_miss'; }
                    catch (\Throwable) { $hit=false; $reason='provider_unknown'; }
                    $this->rights($key,$now);
                }
            }
        }
        $prepared=$current;
        if (!$hit) {
            $this->store->assertIdle(); $prepared=$this->rebuilder->rebuild($key,$now); $key->assertSameRoute($prepared->key);
            $this->rights($prepared->key,$now);
        }
        $this->store->assertIdle(); $this->admission->admit($prepared,$now); $this->rights($prepared->key,$now);
        if ($hit && !$this->store->touch($key,$now)) { throw new \LogicException('Cache invalidated during admission'); }
        return new Resolution($prepared,$hit?$descriptor:null,$reason);
    }

    public function warm(Key $key,WarmRequest $request,WarmPolicy $policy,UtcInstant $now): ?Descriptor
    {
        $rights=$this->rights($key,$now,true); $policy->assertFor($key,$now,$request->popularity,$request->estimate);
        if ($key->kind!==Kind::ProviderResource || !$this->provider->capabilities($key)->allows($request->operationKind,($this->clock)())) {
            throw new \LogicException('Verified explicit cache create required; implicit cache has no object lifecycle');
        }
        $intent=$this->accounting->intent($key,$request,$now); $prior=$this->store->existingWarm($intent);
        if ($prior!==null) { $saved=$this->store->get($key); return $prior->status==='accounted' && $saved!==null && $saved->usable($now)?$saved:null; }
        $existing=$this->store->get($key);
        if ($existing!==null && $existing->usable($now) && $request->operationKind==='create') { return $existing; }
        if ($request->operationKind==='refresh' && ($existing===null || !$existing->usable($now))) { throw new \LogicException('Refresh requires a live demanded resource'); }
        $state=$this->store->beginWarm($intent,$policy);
        if (!$state->fresh) { return $state->status==='accounted'?$this->store->get($key):null; }
        try {
            $reservation=$this->accounting->reserve($request,$now); $this->rights($key,$now,true);
            if (Time::compare(($this->clock)(),$request->admission->deadline)>=0 || Time::compare(($this->clock)(),$request->expiresAt)>=0) {
                throw new \LogicException('Warm deadline expired before IO');
            }
        } catch (\Throwable $error) {
            $json=$this->accounting->fact($state->intent,new ProviderResult('unknown'),false);
            $this->store->finishWarm($state->intent,null,$json,'unknown'); $this->accounting->export($json); $this->store->accounted($state->intent);
            throw new \LogicException('Warm admission/current rights refused');
        }
        $this->store->sent($state->intent,$reservation); $this->store->assertIdle();
        try { $reply=$request->operationKind==='refresh'
            ?$this->provider->refresh($existing,$request->operation,$request->expiresAt):$this->provider->create($key,$request->operation,$request->expiresAt); }
        catch (\Throwable) { $reply=new ProviderResult('unknown'); }
        $descriptor=null;
        if ($reply->status==='created') {
            try {
                $descriptor=new Descriptor($key,$reply->resource ?? throw new \LogicException('Missing cache ref'),$state->intent->createdAt,
                    $reply->expiresAt ?? throw new \LogicException('Missing expiry'),$now,$rights->evidence);
            } catch (\Throwable) { $reply=new ProviderResult('unknown'); }
        }
        $json=$this->accounting->fact($state->intent,$reply);
        $this->store->finishWarm($state->intent,$descriptor,$json,$descriptor===null?'unknown':'known');
        $this->accounting->export($json); $this->store->accounted($state->intent);
        $this->rights($key,$now,true);
        $saved=$this->store->get($key);
        return $saved!==null && $saved->usable($now)?$saved:null;
    }

    /** Crash recovery exports a retained canonical unknown fact; never blindly repeats cache create. */
    public function reconcileWarm(): bool
    {
        $state=$this->store->nextWarm(); if ($state===null) { return false; }
        if (in_array($state->status,['pending','sent'],true)) {
            $this->store->finishWarm($state->intent,null,$state->intent->unknownUsageJson,'unknown');
        }
        $this->accounting->export($state->usageJson); $this->store->accounted($state->intent); return true;
    }

    public function revoke(Key $key,UtcInstant $now,string $reason='owner_revoke'): ?Deletion
    {
        $deletion=$this->store->invalidate($key,$reason,$now);
        return $deletion===null?null:$this->erase($deletion,$now);
    }
    public function reconcileDeletion(UtcInstant $now): bool
    { $pending=$this->store->nextDeletion(); if ($pending===null) { return false; } $this->erase($pending,$now); return true; }
    public function retireIdle(UtcInstant $before,UtcInstant $now,int $limit): int
    {
        if ($limit<1 || $limit>1000) { throw new \InvalidArgumentException('Bounded retirement required'); }
        $keys=$this->store->idle($before,$limit); foreach ($keys as $key) { $this->revoke($key,$now,'idle_retire'); } return count($keys);
    }
    private function erase(Deletion $deletion,UtcInstant $now): Deletion
    {
        if (in_array($deletion->status,['deleted','expired','local_only'],true)) { return $deletion; }
        $d=$deletion->descriptor;
        if (!$d->key->kind->external()) { $reply=new Deletion($deletion->id,$d,'local_only'); }
        elseif (Time::compare($now,$d->expiresAt)>=0) { $reply=new Deletion($deletion->id,$d,'expired'); }
        elseif (!$this->provider->capabilities($d->key)->allows('delete',($this->clock)())) { $reply=new Deletion($deletion->id,$d,'unsupported'); }
        else {
            $this->store->assertIdle();
            try {
                if ($deletion->status==='unknown') {
                    $result=$this->provider->capabilities($d->key)->allows('status',($this->clock)())
                        ?$this->provider->status($d->key,$deletion->id):new ProviderResult('unknown');
                } elseif ($this->store->claimDeletion($deletion)) { $result=$this->provider->delete($d,$deletion->id); }
                else { $result=new ProviderResult('unknown'); }
                $reply=new Deletion($deletion->id,$d,$result->status==='deleted'?'deleted':'unknown',$result->status==='deleted'?$result->receipt:null);
            } catch (\Throwable) { $reply=new Deletion($deletion->id,$d,'unknown'); }
        }
        $this->store->saveDeletion($reply); return $reply;
    }
    private function rights(Key $key,UtcInstant $now,bool $warm=false): \AgenToolsPlatform\Core\Models\Cache\Rights
    {
        $now=($this->clock)(); $key->assertCurrent($now); $this->store->assertIdle(); $rights=$this->guard->current($key,$now);
        $rights->assertFor($key,$now,$key->kind->external(),$warm); return $rights;
    }
}
