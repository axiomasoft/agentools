<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Budget;

use AgenTools\Contracts\Clock;
use AgenToolsPlatform\Core\Budget\{Admission, AdmissionConflict, AdmissionRequest, BudgetGuard, BudgetPort};
use AgenToolsPlatform\Core\Common\{CanonicalHasher, Ref, UtcInstant};
use AgenToolsPlatform\Core\Usage\{Certainty, Values};
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Usage\{AccountingPending, Repository};

/** Durable technical checkpoint around a required owner. No business debit implementation. */
final readonly class Reservations implements BudgetPort
{
    public function __construct(private Repository $usage, private BudgetPort $owner, private BudgetGuard $guard,
        private Clock $clock, private CanonicalHasher $hasher, private ?StatusPort $lookup = null) {}

    public function assertRepository(Repository $repository): void
    { if ($this->usage !== $repository) { throw new \InvalidArgumentException('Accounting must share the tenant repository'); } }

    public function admit(AdmissionRequest $request): Admission
    {
        $this->check($request); $this->bind($request); $db=$this->usage->db;
        $claim=$db->transaction(function () use ($request,$db): bool {
            $this->usage->lockTenant();
            $row=$db->row('SELECT status FROM '.$db->table('accounting_reservation').' WHERE tenant_id=:tenant AND id=:id FOR UPDATE',$this->key($request));
            if ($row===null || in_array($row['status'],['active','settled','released'],true)) { return false; }
            $op=$db->row('SELECT state,lease_until>clock_timestamp() AS live FROM '.$db->table('accounting_operation').'
                WHERE tenant_id=:tenant AND admission_key=:id AND kind=\'admit\'',$this->key($request));
            if ($op!==null) { return false; } // An ambiguous admission is resolved only via status(same key).
            $db->execute('INSERT INTO '.$db->table('accounting_operation').'
                (tenant_id,id,admission_key,kind,payload_hash,payload,state,lease_until)
                VALUES (:tenant,:op,:id,\'admit\',:hash,CAST(:payload AS jsonb),\'inflight\',clock_timestamp()+make_interval(secs=>:lease))',
                $this->key($request)+['op'=>$this->admitId($request),'hash'=>$request->hash->value,
                    'payload'=>'{}','lease'=>$this->usage->policy->leaseSeconds]);
            return true;
        });
        if (!$claim) { return $this->status($request); }
        try {
            $this->check($request); $reply=$this->owner->status($request);
            if ($reply->status==='not_found') { $this->check($request); $reply=$this->owner->admit($request); }
        } catch (AdmissionConflict $error) { $this->admissionUnknown($request); throw $error; }
        catch (\Throwable) { $this->admissionUnknown($request); return self::unknown(); }
        return $this->saveAdmission($request,$reply);
    }

    public function status(AdmissionRequest $request): Admission
    {
        $this->check($request); $this->bind($request);
        try { $reply=$this->owner->status($request); }
        catch (AdmissionConflict $error) { throw $error; }
        catch (\Throwable) { $this->admissionUnknown($request); return self::unknown(); }
        // A lost admit ACK plus authoritative not_found permits the same-key admission again.
        if ($reply->status==='not_found') {
            $db=$this->usage->db;
            $db->transaction(function () use ($db,$request): void {
                $this->usage->lockTenant();
                $db->execute('DELETE FROM '.$db->table('accounting_operation').' WHERE tenant_id=:tenant AND admission_key=:id
                    AND kind=\'admit\' AND (state=\'unknown\' OR (state=\'inflight\' AND lease_until<=clock_timestamp()))',$this->key($request));
            });
            $this->check($request); return $reply;
        }
        return $this->saveAdmission($request,$reply);
    }

    public function settle(Ref $reservation, Ref $usageEvent): void
    {
        $event=$this->usage->event($usageEvent); $request=$this->request($reservation); $this->check($request);
        $content=$request->content; $plan=isset($content['plan'])?Values::object($content['plan']):[];
        if ($event->certainty!==Certainty::Final || ($content['invocation_ref'] ?? $plan['invocation_id'] ?? null)!==$event->invocation->value
            || ($content['business_operation_ref'] ?? $plan['business_operation'] ?? null)!==$event->toArray()['business_operation_ref']) { throw new AccountingPending(); }
        $this->perform($request,$reservation,'settle',['usage_event_ref'=>$event->id->value,'usage_hash'=>$event->hash->value,'supersedes_event_id'=>$event->supersedes],
            function () use ($reservation,$usageEvent): ?Admission { $this->owner->settle($reservation,$usageEvent); return null; });
    }

    public function release(Ref $reservation, string $provenNotSentReason): void
    {
        $request=$this->request($reservation); $row=$this->checkpoint($request);
        if ($row['exposure']!=='not_sent' || !is_string($row['not_sent_evidence'])
            || $provenNotSentReason!=='proven_not_sent:'.$row['not_sent_evidence']) { throw new AccountingPending(); }
        $this->perform($request,$reservation,'release',['reason'=>$provenNotSentReason],
            function () use ($reservation,$provenNotSentReason): ?Admission { $this->owner->release($reservation,$provenNotSentReason); return null; });
    }

    public function renew(Ref $reservation, UtcInstant $deadline): Admission
    {
        $request=$this->request($reservation);
        if (new \DateTimeImmutable($deadline->value)<=$this->clock->now()) { return self::unknown(); }
        $reply=$this->perform($request,$reservation,'renew',['deadline'=>$deadline->value],
            fn (): Admission => $this->owner->renew($reservation,$deadline)) ?? self::unknown();
        $this->check($request); return $reply;
    }

    /** Durable scanner entrypoint; queues are wake signals. Never invokes the provider. */
    public function reconcileOne(): bool
    {
        $db=$this->usage->db; $db->assertIdle();
        $row=$db->row('SELECT r.reservation_ref,r.id,o.kind,o.payload,r.request FROM '.$db->table('accounting_operation').' o
            JOIN '.$db->table('accounting_reservation').' r ON r.tenant_id=o.tenant_id AND r.id=o.admission_key
            WHERE o.tenant_id=:tenant AND (o.state=\'unknown\' OR (o.state=\'inflight\' AND o.lease_until<=clock_timestamp()))
            ORDER BY o.updated_at LIMIT 1',['tenant'=>$this->usage->tenant->value]);
        if ($row===null) {
            // An initial status read can time out before an effect-capable admit intent exists.
            $row=$db->row('SELECT reservation_ref,id,\'admit\' AS kind,\'{}\' AS payload,request
                FROM '.$db->table('accounting_reservation').' WHERE tenant_id=:tenant AND status=\'unknown\'
                AND reservation_ref IS NULL AND NOT EXISTS (SELECT 1 FROM '.$db->table('accounting_operation').' o
                    WHERE o.tenant_id=:tenant2 AND o.admission_key=accounting_reservation.id)
                ORDER BY updated_at LIMIT 1',['tenant'=>$this->usage->tenant->value,'tenant2'=>$this->usage->tenant->value]);
        }
        if ($row===null) { return false; }
        $request=new AdmissionRequest(new Ref($this->usage->tenant,Connection::text($row,'id')),
            RequestCodec::decode(Connection::text($row,'request')),$this->hasher);
        try {
            if ($row['kind']==='admit') {
                if ($this->status($request)->status==='not_found') { $this->admit($request); }
            } else {
                $reservation=new Ref($this->usage->tenant,Connection::text($row,'reservation_ref'));
                $payload=Values::object(json_decode(Connection::text($row,'payload'),true,flags:JSON_THROW_ON_ERROR));
                match ($row['kind']) {
                    'settle'=>$this->settle($reservation,new Ref($this->usage->tenant,Values::text($payload['usage_event_ref']))),
                    'release'=>$this->release($reservation,Values::text($payload['reason'])),
                    'renew'=>$this->renew($reservation,new UtcInstant(Values::text($payload['deadline']))),
                    default=>throw new AccountingPending(),
                };
            }
        } catch (AccountingPending) { /* Durable unknown/manual projection remains authoritative locally. */ }
        return true;
    }

    /** Only a trusted caller with positive evidence can record no-send. An outbound intent prevents it. */
    public function proveNotSent(Ref $reservation, Ref $evidence): void
    {
        $this->usage->tenant->assertSame($evidence->tenant); $request=$this->request($reservation); $this->check($request); $db=$this->usage->db;
        $db->transaction(function () use ($request,$evidence,$db): void {
            $this->usage->lockTenant(); $row=$this->checkpoint($request);
            $plan=isset($request->content['plan'])?Values::object($request->content['plan']):[];
            $invocation=$request->content['invocation_ref'] ?? $plan['invocation_id'] ?? null;
            $sent=$db->row('SELECT 1 FROM '.$db->table('invocation_intents').' WHERE tenant_id=:tenant AND id=:id',
                ['tenant'=>$this->usage->tenant->value,'id'=>is_string($invocation)?$invocation:'']);
            if ($row['status']!=='active' || $sent!==null || $invocation===null) { throw new AccountingPending(); }
            $db->execute('UPDATE '.$db->table('accounting_reservation').' SET exposure=\'not_sent\',not_sent_evidence=:proof,version=version+1,
                updated_at=clock_timestamp() WHERE tenant_id=:tenant AND id=:id',$this->key($request)+['proof'=>$evidence->value]);
        });
    }

    /** @return array<string,mixed> */
    public function checkpoint(AdmissionRequest $request): array
    {
        $this->usage->tenant->assertSame($request->operationKey->tenant);
        $db=$this->usage->db;
        return $db->row('SELECT r.*,CASE WHEN EXISTS (SELECT 1 FROM '.$db->table('accounting_operation').' o
            WHERE o.tenant_id=r.tenant_id AND o.admission_key=r.id AND o.state=\'inflight\' AND o.lease_until<=clock_timestamp())
            THEN \'unknown\' ELSE r.status END AS status FROM '.$db->table('accounting_reservation').' r WHERE r.tenant_id=:tenant AND r.id=:id',
            $this->key($request)) ?? throw new AccountingPending();
    }

    /**
     * @param array<string,mixed> $payload
     * @param \Closure(): ?Admission $send
     */
    private function perform(AdmissionRequest $request, Ref $reservation, string $kind, array $payload, \Closure $send): ?Admission
    {
        $this->check($request); $db=$this->usage->db;
        $hash=$this->hasher->hash($this->usage->tenant,'reservation-operation',[$request->hash->value,$reservation->value,$kind,$payload])->value;
        $op=new Operation(new Ref($this->usage->tenant,'accounting:'.substr($hash,8)),$request,$reservation,$kind,$hash,$payload);
        $claim=$db->transaction(function () use ($db,$request,$kind,$payload,$op): array {
            $this->usage->lockTenant(); $row=$this->checkpoint($request);
            $before=$db->row('SELECT *,lease_until>clock_timestamp() AS live FROM '.$db->table('accounting_operation').'
                WHERE tenant_id=:tenant AND id=:id',['tenant'=>$this->usage->tenant->value,'id'=>$op->id->value]);
            if ($before!==null) { return $before; }
            $correction=$kind==='settle' && $row['status']==='settled' && is_string($payload['supersedes_event_id'] ?? null)
                && $db->row('SELECT 1 FROM '.$db->table('accounting_operation').' WHERE tenant_id=:tenant AND admission_key=:id
                    AND kind=\'settle\' AND state=\'confirmed\' AND payload->>\'usage_event_ref\'=:prior',
                    $this->key($request)+['prior'=>$payload['supersedes_event_id']])!==null;
            if (($row['status']!=='active' && !$correction) || ($kind==='settle' && $row['exposure']!=='known')
                || ($kind==='release' && $row['exposure']!=='not_sent')) { throw new AccountingPending(); }
            $pending=$db->row('SELECT 1 FROM '.$db->table('accounting_operation').' WHERE tenant_id=:tenant AND admission_key=:id
                AND state IN (\'inflight\',\'unknown\',\'manual_resolution_required\')',$this->key($request));
            if ($pending!==null) { throw new AccountingPending(); }
            $db->execute('INSERT INTO '.$db->table('accounting_operation').'
                (tenant_id,id,admission_key,kind,payload_hash,payload,state,lease_until)
                VALUES (:tenant,:id,:key,:kind,:hash,CAST(:payload AS jsonb),\'inflight\',clock_timestamp()+make_interval(secs=>:lease))',
                ['tenant'=>$this->usage->tenant->value,'id'=>$op->id->value,'key'=>$request->operationKey->value,'kind'=>$kind,
                    'hash'=>$op->hash,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'lease'=>$this->usage->policy->leaseSeconds]);
            $this->setReservation($request,match($kind) {'settle'=>'settling','release'=>'releasing',default=>'unknown'});
            return ['state'=>'fresh','epoch'=>1,'attempts'=>1,'live'=>true];
        });
        if ($claim['state']==='confirmed') { return $kind==='renew' ? $this->savedAdmission($this->checkpoint($request)) : null; }
        $epoch=Connection::number($claim,'epoch');
        if ($claim['state']!=='fresh') {
            if (($claim['state']==='inflight' && $claim['live']===true) || $claim['state']==='manual_resolution_required') { throw new AccountingPending(); }
            $allowed=$db->transaction(function () use ($db,$op,$epoch): bool {
                $this->usage->lockTenant();
                $row=$db->row('SELECT lookups FROM '.$db->table('accounting_operation').' WHERE tenant_id=:tenant AND id=:id AND epoch=:epoch',
                    ['tenant'=>$this->usage->tenant->value,'id'=>$op->id->value,'epoch'=>$epoch]);
                if ($row===null) { return false; }
                $manual=$this->lookup===null || Connection::number($row,'lookups') >= $this->usage->policy->maxAttempts;
                $db->execute('UPDATE '.$db->table('accounting_operation').' SET lookups=lookups+1,state=:state,updated_at=clock_timestamp()
                    WHERE tenant_id=:tenant AND id=:id AND epoch=:epoch AND state IN (\'inflight\',\'unknown\')',
                    ['state'=>$manual?'manual_resolution_required':'unknown','tenant'=>$this->usage->tenant->value,'id'=>$op->id->value,'epoch'=>$epoch]);
                if ($manual) { $this->setReservation($op->request,'unknown'); }
                return !$manual;
            });
            if (!$allowed) { throw new AccountingPending(); }
            try {
                $this->check($request); $resolution=$this->lookup?->lookup($op) ?? throw new AccountingPending();
                $op->id->assertSame($resolution->operation);
                if ($resolution->payloadHash!==$op->hash || $resolution->state==='unknown') { throw new AccountingPending(); }
                if ($resolution->state==='applied') {
                    return $this->complete($op,$epoch,$resolution->renewedAdmission,$resolution->proof);
                }
            } catch (\Throwable) { $this->uncertain($op,$epoch); throw new AccountingPending(); }
            $epoch=$db->transaction(function () use ($db,$op,$epoch,$claim): int {
                $this->usage->lockTenant();
                if (Connection::number($claim,'attempts') >= $this->usage->policy->maxAttempts) {
                    $db->execute('UPDATE '.$db->table('accounting_operation').' SET state=\'manual_resolution_required\'
                        WHERE tenant_id=:tenant AND id=:id AND epoch=:epoch', ['tenant'=>$this->usage->tenant->value,'id'=>$op->id->value,'epoch'=>$epoch]);
                    return 0;
                }
                $n=$db->execute('UPDATE '.$db->table('accounting_operation').' SET state=\'inflight\',epoch=epoch+1,attempts=attempts+1,
                    lease_until=clock_timestamp()+make_interval(secs=>:lease),updated_at=clock_timestamp()
                    WHERE tenant_id=:tenant AND id=:id AND epoch=:epoch AND state IN (\'inflight\',\'unknown\',\'not_applied\')',
                    ['lease'=>$this->usage->policy->leaseSeconds,'tenant'=>$this->usage->tenant->value,'id'=>$op->id->value,'epoch'=>$epoch])->rowCount();
                return $n===1 ? $epoch+1 : 0;
            });
            if ($epoch===0) { throw new AccountingPending(); }
        }
        try { $this->check($request); $reply=$send(); }
        catch (\Throwable) { $this->uncertain($op,$epoch); throw new AccountingPending(); }
        return $this->complete($op,$epoch,$reply,null);
    }

    private function complete(Operation $op, int $epoch, ?Admission $reply, ?Ref $proof): ?Admission
    {
        // Current revocation can stop further IO, but cannot erase a proven owner outcome.
        if ($op->kind==='renew') {
            if ($reply===null || $reply->status!=='admitted') { $this->uncertain($op,$epoch); throw new AccountingPending(); }
            $this->assertReply($op->request,$reply); $op->reservation->assertSame($reply->reservationRef ?? throw new AccountingPending());
            if (new \DateTimeImmutable(($reply->expiresAt ?? throw new AccountingPending())->value) <= $this->clock->now()) { $this->uncertain($op,$epoch); throw new AccountingPending(); }
        }
        $db=$this->usage->db;
        $ok=$db->transaction(function () use ($db,$op,$epoch,$reply,$proof): bool {
            $this->usage->lockTenant();
            $n=$db->execute('UPDATE '.$db->table('accounting_operation').' SET state=\'confirmed\',proof_ref=:proof,updated_at=clock_timestamp()
                WHERE tenant_id=:tenant AND id=:id AND epoch=:epoch AND state IN (\'inflight\',\'unknown\')',
                ['proof'=>$proof?->value,'tenant'=>$this->usage->tenant->value,'id'=>$op->id->value,'epoch'=>$epoch])->rowCount();
            if ($n!==1) { return false; }
            $this->setReservation($op->request,match($op->kind) {'settle'=>'settled','release'=>'released',default=>'active'});
            if ($reply!==null) { $db->execute('UPDATE '.$db->table('accounting_reservation').' SET admission=CAST(:reply AS jsonb)
                WHERE tenant_id=:tenant AND id=:id',$this->key($op->request)+['reply'=>json_encode($reply->toArray(),JSON_THROW_ON_ERROR)]); }
            return true;
        });
        if (!$ok) { throw new AccountingPending(); }
        return $reply;
    }

    private function uncertain(Operation $op, int $epoch): void
    {
        $db=$this->usage->db;
        $db->transaction(function () use ($db,$op,$epoch): void {
            $this->usage->lockTenant();
            $n=$db->execute('UPDATE '.$db->table('accounting_operation').' SET state=\'unknown\',updated_at=clock_timestamp()
                WHERE tenant_id=:tenant AND id=:id AND epoch=:epoch AND state=\'inflight\'',
                ['tenant'=>$this->usage->tenant->value,'id'=>$op->id->value,'epoch'=>$epoch])->rowCount();
            if ($n===1) { $this->setReservation($op->request,'unknown'); }
        });
    }

    private function bind(AdmissionRequest $request): void
    {
        $allowed=['operation_key','tenant_ref','actor_ref','subject_ref','purpose','capability','task_type','turn_ref','run_ref','invocation_ref',
            'business_operation_ref','bundle_ref','deployment_ref','estimated_units','estimated_amount','pricing_version','desired_deadline','profile_hash','context_hash','policy_versions',
            'agent_principal_ref','plan','context_scope','subject','action_request_hash','scope_hash'];
        if (array_diff(array_keys($request->content),$allowed)!==[]) { throw new \InvalidArgumentException('Only technical admission metadata is allowed'); }
        if (isset($request->content['tenant_ref']) && $request->content['tenant_ref']!==$this->usage->tenant->value) {
            throw new \InvalidArgumentException('Admission tenant binding changed');
        }
        $this->assertTechnical($request->content);
        $db=$this->usage->db;
        $conflict=$db->transaction(function () use ($db,$request): bool {
            $this->usage->lockTenant();
            $row=$db->row('SELECT request_hash FROM '.$db->table('accounting_reservation').' WHERE tenant_id=:tenant AND id=:id',$this->key($request));
            if ($row!==null) {
                if ($row['request_hash']===$request->hash->value) {
                    return $db->row('SELECT 1 FROM '.$db->table('accounting_quarantine').' WHERE tenant_id=:tenant AND id=:id AND code=\'ADMISSION_PAYLOAD_CONFLICT\'',$this->key($request))!==null;
                }
                $db->execute('INSERT INTO '.$db->table('accounting_quarantine').'(tenant_id,id,incoming_hash,accepted_hash,code)
                    VALUES (:tenant,:id,:incoming,:accepted,\'ADMISSION_PAYLOAD_CONFLICT\') ON CONFLICT DO NOTHING',
                    $this->key($request)+['incoming'=>$request->hash->value,'accepted'=>Connection::text($row,'request_hash')]);
                $this->setReservation($request,'unknown'); return true;
            }
            $this->usage->assertCapacity();
            $db->execute('INSERT INTO '.$db->table('accounting_reservation').'(tenant_id,id,request_hash,request,status)
                VALUES (:tenant,:id,:hash,CAST(:request AS jsonb),\'pending\')',$this->key($request)+[
                    'hash'=>$request->hash->value,'request'=>json_encode($request->content,JSON_THROW_ON_ERROR)]);
            return false;
        });
        if ($conflict) { throw new AdmissionConflict(); }
    }

    private function saveAdmission(AdmissionRequest $request, Admission $reply): Admission
    {
        $this->assertReply($request,$reply); $db=$this->usage->db;
        $saved=$db->transaction(function () use ($request,$reply,$db): Admission {
            $this->usage->lockTenant(); $row=$this->checkpoint($request);
            if (in_array($row['status'],['settled','released'],true)) { return new Admission('denied',reason:'RESERVATION_CLOSED'); }
            $pending=$db->row('SELECT 1 FROM '.$db->table('accounting_operation').' WHERE tenant_id=:tenant AND admission_key=:id
                AND kind<>\'admit\' AND state IN (\'inflight\',\'unknown\',\'manual_resolution_required\')',$this->key($request));
            if ($pending!==null) { return self::unknown(); }
            if ($row['reservation_ref']!==null && $reply->reservationRef!==null && $row['reservation_ref']!==$reply->reservationRef->value) { throw new AdmissionConflict(); }
            $status=match($reply->status) {'admitted'=>'active','denied','unavailable'=>'pending',default=>'unknown'};
            $db->execute('UPDATE '.$db->table('accounting_reservation').' SET status=:status,admission=CAST(:reply AS jsonb),
                reservation_ref=COALESCE(reservation_ref,:reservation),version=version+1,updated_at=clock_timestamp()
                WHERE tenant_id=:tenant AND id=:id',$this->key($request)+['status'=>$status,'reply'=>json_encode($reply->toArray(),JSON_THROW_ON_ERROR),'reservation'=>$reply->reservationRef?->value]);
            $db->execute('UPDATE '.$db->table('accounting_operation').' SET state=:state,updated_at=clock_timestamp()
                WHERE tenant_id=:tenant AND admission_key=:id AND kind=\'admit\' AND state<>\'confirmed\'',
                $this->key($request)+['state'=>in_array($reply->status,['admitted','denied'],true)?'confirmed':'unknown']);
            return $reply;
        });
        $this->check($request);
        return $saved->status==='admitted' && new \DateTimeImmutable(($saved->expiresAt ?? throw new AccountingPending())->value) <= $this->clock->now()
            ? new Admission('denied',reason:'ADMISSION_EXPIRED') : $saved;
    }

    private function admissionUnknown(AdmissionRequest $request): void
    {
        $db=$this->usage->db;
        $db->transaction(function () use ($db,$request): void {
            $this->usage->lockTenant();
            $db->execute('UPDATE '.$db->table('accounting_operation').' SET state=\'unknown\',updated_at=clock_timestamp()
                WHERE tenant_id=:tenant AND admission_key=:id AND kind=\'admit\' AND state=\'inflight\'',$this->key($request));
            $db->execute('UPDATE '.$db->table('accounting_reservation').' SET status=\'unknown\',version=version+1,updated_at=clock_timestamp()
                WHERE tenant_id=:tenant AND id=:id AND status NOT IN (\'settled\',\'released\')',$this->key($request));
        });
    }

    private function request(Ref $reservation): AdmissionRequest
    {
        $this->usage->tenant->assertSame($reservation->tenant); $db=$this->usage->db;
        $row=$db->row('SELECT id,request,request_hash FROM '.$db->table('accounting_reservation').' WHERE tenant_id=:tenant AND reservation_ref=:reservation',
            ['tenant'=>$this->usage->tenant->value,'reservation'=>$reservation->value]) ?? throw new AccountingPending();
        $content=RequestCodec::decode(Connection::text($row,'request'));
        $request=new AdmissionRequest(new Ref($this->usage->tenant,Connection::text($row,'id')),$content,$this->hasher);
        if ($request->hash->value!==Connection::text($row,'request_hash')) { throw new AdmissionConflict(); }
        return $request;
    }

    private function check(AdmissionRequest $request): void
    {
        $this->usage->tenant->assertSame($request->operationKey->tenant); $this->usage->db->assertIdle();
        $this->guard->assertCurrent($request,new UtcInstant($this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')));
    }
    private function assertReply(AdmissionRequest $request, Admission $reply): void
    {
        foreach ([$reply->admissionRef,$reply->reservationRef] as $ref) { if ($ref!==null) { $request->operationKey->tenant->assertSame($ref->tenant); } }
        if ($reply->status==='admitted' && $reply->reservationRef===null) { throw new AccountingPending(); }
    }
    /** @param array<string,mixed> $row */
    private function savedAdmission(array $row): Admission
    {
        $d=Values::object(json_decode(Connection::text($row,'admission'),true,flags:JSON_THROW_ON_ERROR));
        $limits=[];
        foreach (Values::object($d['limits'] ?? null) as $key=>$value) { $limits[$key]=Values::integer($value); }
        $t=$this->usage->tenant;
        return new Admission(Values::text($d['status']),isset($d['admission_ref'])?new Ref($t,Values::text($d['admission_ref'])):null,
            isset($d['reservation_ref'])?new Ref($t,Values::text($d['reservation_ref'])):null,isset($d['expires_at'])?new UtcInstant(Values::text($d['expires_at'])):null,
            Values::text($d['owner_revision']),$limits,Values::optionalText($d['reason']));
    }
    private function setReservation(AdmissionRequest $request, string $status): void
    {
        $this->usage->db->execute('UPDATE '.$this->usage->db->table('accounting_reservation').' SET status=:status,version=version+1,updated_at=clock_timestamp()
            WHERE tenant_id=:tenant AND id=:id',$this->key($request)+['status'=>$status]);
    }
    /** @return array{tenant:string,id:string} */
    private function key(AdmissionRequest $request): array { return ['tenant'=>$this->usage->tenant->value,'id'=>$request->operationKey->value]; }
    private function admitId(AdmissionRequest $request): string
    { return 'admit:'.substr($this->hasher->hash($this->usage->tenant,'admission-operation',[$request->operationKey->value,$request->hash->value])->value,8); }
    private static function unknown(): Admission { return new Admission('unknown',reason:'OWNER_STATUS_LOOKUP_REQUIRED'); }
    /** @param array<mixed> $content */
    private function assertTechnical(array $content): void
    {
        foreach ($content as $key=>$value) {
            if (in_array($key,['prompt','messages','text','body','raw','secret','api_key','credentials','authorization','child_text'],true)) {
                throw new \InvalidArgumentException('Raw accounting metadata is forbidden');
            }
            if (is_array($value)) { $this->assertTechnical($value); }
            elseif ($value instanceof \stdClass) { $this->assertTechnical((array)$value); }
        }
    }
}
