<?php
declare(strict_types=1);
namespace AgenToolsPlatform\ModelAdapter\Tests\Cache;
use AgenToolsPlatform\Core\Common\{IntegrityConflict,Ref,UtcInstant};
use AgenToolsPlatform\Core\Models\Cache\{Deletion,Descriptor,Key,Store,WarmIntent,WarmPolicy,WarmState};
use AgenToolsPlatform\Core\Models\Time;

/** Contract-scripted test double only; real durability is tested in the separate PG partition. */
final class MemoryStore implements Store
{
    /** @var array<string,Descriptor> */ public array $entries=[];
    /** @var array<string,bool> */ public array $blocked=[];
    /** @var array<string,Deletion> */ public array $deletions=[];
    /** @var array<string,WarmState> */ public array $warms=[];
    /** @var array<string,string> */ public array $warmEntries=[];
    public function __construct(private Fixture $f) {}
    public function assertIdle(): void {}
    public function get(Key $key): ?Descriptor
    {
        $d=$this->entries[$key->id->value]??null; if ($d===null) { return null; }
        if (isset($this->blocked[$key->id->value]) || (isset($this->warmEntries[$key->id->value]) && $this->warms[$this->warmEntries[$key->id->value]]->status!=='accounted')) {
            return new Descriptor($d->key,$d->artifact,$d->createdAt,$d->expiresAt,$d->lastUsed,$d->rightsEvidence,'invalidated');
        } return $d;
    }
    public function put(Descriptor $descriptor): void { $this->entries[$descriptor->key->id->value]=$descriptor; }
    public function touch(Key $key,UtcInstant $now): bool
    { $d=$this->get($key); return $d!==null && $d->usable($now); }
    public function invalidate(Key $key,string $reason,UtcInstant $now): ?Deletion
    {
        if ($reason!=='idle_retire') { $this->blocked[$key->id->value]=true; }
        $d=$this->entries[$key->id->value]??null; if ($d===null) { return null; }
        $this->entries[$key->id->value]=new Descriptor($key,$d->artifact,$d->createdAt,$d->expiresAt,$d->lastUsed,$d->rightsEvidence,'invalidated');
        $id='delete:'.$key->id->value;
        return $this->deletions[$id]??=new Deletion($this->f->ref($id),$this->entries[$key->id->value],'pending');
    }
    public function claimDeletion(Deletion $deletion): bool
    { if (($this->deletions[$deletion->id->value]->status??null)!=='pending') { return false; } $this->deletions[$deletion->id->value]=new Deletion($deletion->id,$deletion->descriptor,'unknown'); return true; }
    public function saveDeletion(Deletion $deletion): void
    { if (($this->deletions[$deletion->id->value]->status??null)!=='deleted') { $this->deletions[$deletion->id->value]=$deletion; } }
    public function nextDeletion(): ?Deletion
    { foreach ($this->deletions as $d) { if (in_array($d->status,['pending','unknown','unsupported'],true)) { return $d; } } return null; }
    public function idle(UtcInstant $before,int $limit): array
    { $keys=[]; foreach ($this->entries as $d) { if ($d->state==='active' && Time::compare($d->lastUsed,$before)<0) { $keys[]=$d->key; } } return array_slice($keys,0,$limit); }
    public function existingWarm(WarmIntent $intent): ?WarmState
    {
        $s=$this->warms[$intent->id->value]??null;
        if ($s!==null) { $intent->requestHash->assertSame($s->intent->requestHash); }
        return $s;
    }
    public function beginWarm(WarmIntent $intent,WarmPolicy $policy): WarmState
    {
        $id=$intent->id->value; if (isset($this->warms[$id])) { $s=$this->warms[$id]; $intent->requestHash->assertSame($s->intent->requestHash); return new WarmState($s->intent,$s->status,$s->usageJson,false,$s->descriptor); }
        if (count($this->warms)>=$policy->maxOperations || array_sum(array_map(static fn (WarmState $s): int=>$s->intent->estimate,$this->warms))>$policy->maxEstimatedUnits-$intent->estimate) { throw new \LogicException('Warm quota exceeded'); }
        if (isset($this->blocked[$intent->key->id->value])) { throw new \LogicException('Revoked warm key'); }
        return $this->warms[$id]=new WarmState($intent,'pending',$intent->unknownUsageJson,true);
    }
    public function sent(WarmIntent $intent,Ref $reservation): void
    { $s=$this->warms[$intent->id->value]; if ($s->status!=='pending') { throw new IntegrityConflict(); } $this->warms[$intent->id->value]=new WarmState($s->intent,'sent',$s->usageJson); }
    public function finishWarm(WarmIntent $intent,?Descriptor $descriptor,string $usageJson,string $status): void
    {
        $this->warms[$intent->id->value]=new WarmState($intent,$status,$usageJson,false,$descriptor);
        if ($descriptor!==null) { $this->put($descriptor); $this->warmEntries[$intent->key->id->value]=$intent->id->value; }
    }
    public function accounted(WarmIntent $intent): void
    { $s=$this->warms[$intent->id->value]; $this->warms[$intent->id->value]=new WarmState($s->intent,'accounted',$s->usageJson,false,$s->descriptor); }
    public function nextWarm(): ?WarmState
    { foreach ($this->warms as $s) { if ($s->status!=='accounted') { return $s; } } return null; }
}
