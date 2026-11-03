<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Cache;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,IntegrityConflict,Ref,TenantId,UtcInstant};
use AgenToolsPlatform\Core\Models\Cache\{Deletion,Descriptor,Key,Store,WarmIntent,WarmPolicy,WarmState};
use AgenToolsPlatform\Core\Usage\{UsageEvent,Values};
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Explicit tenant repositories, short transactions, DB-clock claims and fail-closed revocation fences. */
final readonly class Repository implements Store
{
    private Codec $codec;
    public function __construct(public Connection $db,public TenantId $tenant,private CanonicalHasher $hasher,private int $warmLeaseSeconds=30)
    { if ($warmLeaseSeconds<1 || $warmLeaseSeconds>3600) { throw new \InvalidArgumentException('Bounded warm lease required'); } $this->codec=new Codec($hasher); }
    public function assertIdle(): void { $this->db->assertIdle(); }
    public function get(Key $key): ?Descriptor
    {
        $this->check($key); $db=$this->db;
        $row=$db->row('SELECT e.document,CASE WHEN f.id IS NOT NULL OR (e.warm_id IS NOT NULL AND w.status<>\'accounted\') THEN \'invalidated\' ELSE e.state END AS state,
            to_char(e.last_used AT TIME ZONE \'UTC\',\'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS last_used
            FROM '.$db->table('model_cache_entry').' e LEFT JOIN '.$db->table('model_cache_fence').' f ON f.tenant_id=e.tenant_id AND f.id=e.id
            LEFT JOIN '.$db->table('model_cache_warm').' w ON w.tenant_id=e.tenant_id AND w.id=e.warm_id
            WHERE e.tenant_id=:tenant AND e.id=:id',$this->key($key));
        if ($row===null) { return null; }
        $d=$this->codec->descriptor(Connection::text($row,'document'),Connection::text($row,'state'),Connection::text($row,'last_used')); $key->assertSame($d->key); return $d;
    }
    public function put(Descriptor $descriptor): void
    { $this->check($descriptor->key); $this->db->transaction(function () use ($descriptor): void { $this->lock(); $this->writeDescriptor($descriptor,null); }); }
    public function touch(Key $key,UtcInstant $now): bool
    {
        $this->check($key); $db=$this->db;
        return $db->transaction(function () use ($db,$key,$now): bool {
            $this->lock();
            return $db->execute('UPDATE '.$db->table('model_cache_entry').' SET last_used=:now,version=version+1
                WHERE tenant_id=:tenant AND id=:id AND state=\'active\' AND expires_at>clock_timestamp()
                AND NOT EXISTS (SELECT 1 FROM '.$db->table('model_cache_fence').' f WHERE f.tenant_id=:tenant2 AND f.id=:id2)
                AND (warm_id IS NULL OR EXISTS (SELECT 1 FROM '.$db->table('model_cache_warm').' w
                    WHERE w.tenant_id=:tenant3 AND w.id=warm_id AND w.status=\'accounted\'))',
                $this->key($key)+['now'=>$now->value,'tenant2'=>$this->tenant->value,'id2'=>$key->id->value,'tenant3'=>$this->tenant->value])->rowCount()===1;
        });
    }
    public function invalidate(Key $key,string $reason,UtcInstant $now): ?Deletion
    {
        $this->check($key); if ($reason==='' || strlen($reason)>128) { throw new \InvalidArgumentException('Technical revoke reason required'); } $db=$this->db;
        return $db->transaction(function () use ($db,$key,$reason): ?Deletion {
            $this->lock();
            if ($reason!=='idle_retire') { $db->execute('INSERT INTO '.$db->table('model_cache_fence').'(tenant_id,id,reason) VALUES(:tenant,:id,:reason) ON CONFLICT DO NOTHING',$this->key($key)+['reason'=>$reason]); }
            $row=$db->row('SELECT document FROM '.$db->table('model_cache_entry').' WHERE tenant_id=:tenant AND id=:id FOR UPDATE',$this->key($key));
            $db->execute('UPDATE '.$db->table('model_cache_entry').' SET state=\'invalidated\',invalidated_at=clock_timestamp(),version=version+1 WHERE tenant_id=:tenant AND id=:id',$this->key($key));
            if ($row===null) { return null; }
            return $this->deletion($this->codec->descriptor(Connection::text($row,'document'),'invalidated'));
        });
    }
    public function claimDeletion(Deletion $deletion): bool
    {
        $this->check($deletion->descriptor->key); $this->tenant->assertSame($deletion->id->tenant); $db=$this->db;
        return $db->transaction(function () use ($db,$deletion): bool {
            $this->lock(); return $db->execute('UPDATE '.$db->table('model_cache_delete').' SET status=\'unknown\',version=version+1,updated_at=clock_timestamp()
                WHERE tenant_id=:tenant AND id=:id AND status=\'pending\'',['tenant'=>$this->tenant->value,'id'=>$deletion->id->value])->rowCount()===1;
        });
    }
    public function saveDeletion(Deletion $deletion): void
    {
        $this->check($deletion->descriptor->key); $this->tenant->assertSame($deletion->id->tenant); $db=$this->db;
        $db->transaction(function () use ($db,$deletion): void {
            $this->lock(); $db->execute('UPDATE '.$db->table('model_cache_delete').' SET status=:status,receipt_ref=:receipt,
                next_at=clock_timestamp()+interval \'30 seconds\',updated_at=clock_timestamp(),version=version+1
                WHERE tenant_id=:tenant AND id=:id AND status<>\'deleted\'',
                ['status'=>$deletion->status,'receipt'=>$deletion->receipt?->value,'tenant'=>$this->tenant->value,'id'=>$deletion->id->value]);
        });
    }
    public function nextDeletion(): ?Deletion
    {
        $this->assertIdle(); $db=$this->db;
        $row=$db->row('SELECT * FROM '.$db->table('model_cache_delete').' WHERE tenant_id=:tenant AND status IN (\'pending\',\'unknown\',\'unsupported\')
            AND next_at<=clock_timestamp() ORDER BY created_at LIMIT 1',['tenant'=>$this->tenant->value]);
        if ($row===null) { return null; }
        return new Deletion(new Ref($this->tenant,Connection::text($row,'id')),$this->codec->descriptor(Connection::text($row,'descriptor')),
            Connection::text($row,'status'),isset($row['receipt_ref'])?new Ref($this->tenant,Connection::text($row,'receipt_ref')):null);
    }
    public function idle(UtcInstant $before,int $limit): array
    {
        $this->assertIdle(); if ($limit<1 || $limit>1000) { throw new \InvalidArgumentException('Bounded idle scan required'); }
        $rows=$this->db->execute('SELECT document FROM '.$this->db->table('model_cache_entry').' WHERE tenant_id=:tenant AND state=\'active\'
            AND (last_used<:before OR expires_at<=clock_timestamp()) ORDER BY last_used LIMIT :limit',
            ['tenant'=>$this->tenant->value,'before'=>$before->value,'limit'=>$limit])->fetchAll(\PDO::FETCH_ASSOC);
        $keys=[]; foreach ($rows as $row) { $keys[]=$this->codec->descriptor(Connection::text(Values::object($row),'document'))->key; } return $keys;
    }
    public function existingWarm(WarmIntent $intent): ?WarmState
    {
        $this->check($intent->key); $this->tenant->assertSame($intent->id->tenant);
        $row=$this->db->row('SELECT * FROM '.$this->db->table('model_cache_warm').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$this->tenant->value,'id'=>$intent->id->value]);
        if ($row===null) { return null; }
        if ($row['request_hash']!==$intent->requestHash->value) { throw new IntegrityConflict('Warm operation payload conflict'); }
        return $this->warmRow($row);
    }
    public function beginWarm(WarmIntent $intent,WarmPolicy $policy): WarmState
    {
        $this->check($intent->key); $event=$this->usage($intent->unknownUsageJson,$intent); $db=$this->db;
        return $db->transaction(function () use ($db,$intent,$policy,$event): WarmState {
            $this->lock(); $old=$db->row('SELECT * FROM '.$db->table('model_cache_warm').' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$this->tenant->value,'id'=>$intent->id->value]);
            if ($old!==null) {
                if ($old['request_hash']!==$intent->requestHash->value) { throw new IntegrityConflict('Warm operation payload conflict'); }
                return $this->warmRow($old);
            }
            $quota=$db->row('SELECT count(*) AS n,COALESCE(sum(estimate),0) AS units FROM '.$db->table('model_cache_warm').'
                WHERE tenant_id=:tenant AND profile_hash=:profile AND created_at>=:start AND created_at<:end',
                ['tenant'=>$this->tenant->value,'profile'=>$policy->requestedProfile->value,'start'=>$policy->windowStart->value,'end'=>$policy->windowEnd->value]);
            if ($quota===null || Connection::number($quota,'n') >= $policy->maxOperations || Connection::number($quota,'units')>$policy->maxEstimatedUnits-$intent->estimate) {
                throw new \LogicException('Warm technical quota exceeded');
            }
            if ($db->row('SELECT 1 FROM '.$db->table('model_cache_fence').' WHERE tenant_id=:tenant AND id=:id',$this->key($intent->key))!==null) { throw new \LogicException('Revoked warm key'); }
            $db->execute('INSERT INTO '.$db->table('model_cache_warm').'
                (tenant_id,id,request_hash,key_id,profile_hash,estimate,created_at,expires_at,intent,status,usage,usage_hash)
                VALUES(:tenant,:id,:hash,:key,:profile,:estimate,clock_timestamp(),:expiry,CAST(:intent AS jsonb),\'pending\',CAST(:usage AS jsonb),:usage_hash)',
                ['tenant'=>$this->tenant->value,'id'=>$intent->id->value,'hash'=>$intent->requestHash->value,'key'=>$intent->key->id->value,
                    'profile'=>$policy->requestedProfile->value,'estimate'=>$intent->estimate,'expiry'=>$intent->expiresAt->value,
                    'intent'=>Codec::intentJson($intent),'usage'=>$intent->unknownUsageJson,'usage_hash'=>$event->hash->value]);
            return new WarmState($intent,'pending',$intent->unknownUsageJson,true);
        });
    }
    public function sent(WarmIntent $intent,Ref $reservation): void
    {
        $this->check($intent->key); $this->tenant->assertSame($reservation->tenant); $db=$this->db;
        $db->transaction(function () use ($db,$intent,$reservation): void {
            $this->lock(); $n=$db->execute('UPDATE '.$db->table('model_cache_warm').' SET status=\'sent\',reservation_ref=:reservation,
                version=version+1,updated_at=clock_timestamp() WHERE tenant_id=:tenant AND id=:id AND request_hash=:hash AND status=\'pending\'',
                ['reservation'=>$reservation->value,'tenant'=>$this->tenant->value,'id'=>$intent->id->value,'hash'=>$intent->requestHash->value])->rowCount();
            if ($n!==1) { throw new IntegrityConflict('Stale warm intent'); }
        });
    }
    public function finishWarm(WarmIntent $intent,?Descriptor $descriptor,string $usageJson,string $status): void
    {
        $this->check($intent->key); $event=$this->usage($usageJson,$intent); if (!in_array($status,['known','unknown'],true)) { throw new \InvalidArgumentException('Explicit warm outcome required'); }
        if ($descriptor!==null) { $intent->key->assertSame($descriptor->key); } $db=$this->db;
        $db->transaction(function () use ($db,$intent,$descriptor,$usageJson,$status,$event): void {
            $this->lock(); $row=$db->row('SELECT status,usage_hash FROM '.$db->table('model_cache_warm').' WHERE tenant_id=:tenant AND id=:id AND request_hash=:hash FOR UPDATE',
                ['tenant'=>$this->tenant->value,'id'=>$intent->id->value,'hash'=>$intent->requestHash->value]);
            if ($row===null) { throw new IntegrityConflict('Warm operation missing'); }
            if (!in_array($row['status'],['pending','sent'],true)) {
                if ($row['usage_hash']===$event->hash->value) { return; } throw new IntegrityConflict('Immutable warm result conflict');
            }
            $db->execute('UPDATE '.$db->table('model_cache_warm').' SET status=:status,usage=CAST(:usage AS jsonb),usage_hash=:usage_hash,
                descriptor=CAST(:descriptor AS jsonb),version=version+1,updated_at=clock_timestamp() WHERE tenant_id=:tenant AND id=:id',
                ['status'=>$status,'usage'=>$usageJson,'usage_hash'=>$event->hash->value,'descriptor'=>$descriptor===null?null:Codec::descriptorJson($descriptor),
                    'tenant'=>$this->tenant->value,'id'=>$intent->id->value]);
            if ($descriptor!==null) { $this->writeDescriptor($descriptor,$intent->id->value); }
        });
    }
    public function accounted(WarmIntent $intent): void
    {
        $this->check($intent->key); $db=$this->db;
        $db->transaction(function () use ($db,$intent): void {
            $this->lock(); $db->execute('UPDATE '.$db->table('model_cache_warm').' SET status=\'accounted\',version=version+1,updated_at=clock_timestamp()
                WHERE tenant_id=:tenant AND id=:id AND request_hash=:hash AND status IN (\'known\',\'unknown\')',
                ['tenant'=>$this->tenant->value,'id'=>$intent->id->value,'hash'=>$intent->requestHash->value]);
        });
    }
    public function nextWarm(): ?WarmState
    {
        $this->assertIdle(); $db=$this->db;
        $row=$db->row('SELECT * FROM '.$db->table('model_cache_warm').' WHERE tenant_id=:tenant AND status<>\'accounted\'
            AND (status IN (\'known\',\'unknown\') OR updated_at<clock_timestamp()-make_interval(secs=>:lease)) ORDER BY created_at LIMIT 1',
            ['tenant'=>$this->tenant->value,'lease'=>$this->warmLeaseSeconds]);
        return $row===null?null:$this->warmRow($row);
    }
    private function writeDescriptor(Descriptor $d,?string $warmId): void
    {
        $db=$this->db; $blocked=$db->row('SELECT 1 FROM '.$db->table('model_cache_fence').' WHERE tenant_id=:tenant AND id=:id',$this->key($d->key))!==null;
        $old=$db->row('SELECT document,invalidated_at FROM '.$db->table('model_cache_entry').' WHERE tenant_id=:tenant AND id=:id',$this->key($d->key));
        if ($old!==null) {
            $before=$this->codec->descriptor(Connection::text($old,'document'));
            if ($before->artifact->value!==$d->artifact->value) { $this->deletion($before); }
            if ($warmId!==null && $old['invalidated_at']!==null) {
                $stale=$db->row('SELECT 1 FROM '.$db->table('model_cache_warm').' WHERE tenant_id=:tenant AND id=:id AND created_at<=CAST(:invalidated AS timestamptz)',
                    ['tenant'=>$this->tenant->value,'id'=>$warmId,'invalidated'=>Connection::text($old,'invalidated_at')]);
                $blocked=$blocked || $stale!==null;
            }
        }
        $db->execute('INSERT INTO '.$db->table('model_cache_entry').'(tenant_id,id,document,state,created_at,expires_at,last_used,warm_id)
            VALUES(:tenant,:id,CAST(:doc AS jsonb),:state,:created,:expiry,:last,:warm)
            ON CONFLICT(tenant_id,id) DO UPDATE SET document=EXCLUDED.document,state=EXCLUDED.state,created_at=EXCLUDED.created_at,expires_at=EXCLUDED.expires_at,
                last_used=EXCLUDED.last_used,warm_id=EXCLUDED.warm_id,invalidated_at=CASE WHEN EXCLUDED.state=\'active\' THEN NULL ELSE model_cache_entry.invalidated_at END,version=model_cache_entry.version+1',
            $this->key($d->key)+['doc'=>Codec::descriptorJson($d),'state'=>$blocked?'invalidated':$d->state,'created'=>$d->createdAt->value,
                'expiry'=>$d->expiresAt->value,'last'=>$d->lastUsed->value,'warm'=>$warmId]);
        if ($blocked) { $this->deletion($d); }
    }
    private function deletion(Descriptor $d): Deletion
    {
        $id=new Ref($this->tenant,$this->hasher->hash($this->tenant,'cache-delete',[$d->key->id->value,$d->artifact->value])->value); $db=$this->db;
        $db->execute('INSERT INTO '.$db->table('model_cache_delete').'(tenant_id,id,key_id,descriptor,status,expires_at)
            VALUES(:tenant,:id,:key,CAST(:descriptor AS jsonb),\'pending\',:expiry) ON CONFLICT DO NOTHING',
            ['tenant'=>$this->tenant->value,'id'=>$id->value,'key'=>$d->key->id->value,'descriptor'=>Codec::descriptorJson($d),'expiry'=>$d->expiresAt->value]);
        $row=$db->row('SELECT status,receipt_ref FROM '.$db->table('model_cache_delete').' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$this->tenant->value,'id'=>$id->value]) ?? throw new IntegrityConflict('Deletion intent missing');
        return new Deletion($id,$d,Connection::text($row,'status'),isset($row['receipt_ref'])?new Ref($this->tenant,Connection::text($row,'receipt_ref')):null);
    }
    /** @param array<string,mixed> $row */
    private function warmRow(array $row): WarmState
    { return new WarmState($this->codec->intent(Connection::text($row,'intent')),Connection::text($row,'status'),Connection::text($row,'usage'),false,
        isset($row['descriptor'])?$this->codec->descriptor(Connection::text($row,'descriptor')):null); }
    private function usage(string $json,WarmIntent $intent): UsageEvent
    {
        $this->tenant->assertSame($intent->id->tenant); $event=UsageEvent::fromArray(Values::object(json_decode($json,true,flags:JSON_THROW_ON_ERROR)),$this->hasher);
        $this->tenant->assertSame($event->id->tenant);
        if ($event->toArray()['deployment_ref']!==$intent->key->dimensions['deployment_ref']) { throw new IntegrityConflict('Warm usage binding mismatch'); } return $event;
    }
    private function check(Key $key): void { $this->assertIdle(); $this->tenant->assertSame($key->id->tenant); }
    /** @return array{tenant:string,id:string} */
    private function key(Key $key): array { return ['tenant'=>$this->tenant->value,'id'=>$key->id->value]; }
    private function lock(): void { $this->db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))',['key'=>$this->db->table('model_cache_entry').':'.$this->tenant->value]); }
}
