<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Tests\Usage;

use AgenTools\Contracts\Clock;
use AgenToolsPlatform\Core\Budget\{Admission, AdmissionRequest, BudgetGuard, BudgetPort};
use AgenToolsPlatform\Core\Common\{Ref, UtcInstant};
use AgenToolsPlatform\Postgres\Budget\{Operation, OwnerResolution, StatusPort};

/** Scripted external owner, test-only, with commit-before-ACK loss. */
final class Owner implements BudgetPort, BudgetGuard, StatusPort, Clock
{
    public ?string $lost=null;
    public bool $revoked=false;
    public string $lookupState='applied';
    public int $guards=0;
    public function __construct(private Fixture $f) {}
    public function now(): \DateTimeImmutable { return new \DateTimeImmutable('now',new \DateTimeZone('UTC')); }
    public function assertCurrent(AdmissionRequest $request,UtcInstant $now): void
    { ++$this->guards; $this->f->usage->db->assertIdle(); if ($this->revoked) { throw new \LogicException('Current entitlement revoked'); } }
    public function admit(AdmissionRequest $request): Admission
    {
        $reply=$this->f->rig->budget->admit($request);
        $business=$request->content['business_operation_ref'] ?? $request->content['plan']['business_operation'] ?? 'action';
        $this->f->rig->sql('INSERT INTO host_business VALUES (:tenant,:id) ON CONFLICT DO NOTHING',['tenant'=>$request->operationKey->tenant->value,'id'=>$business]);
        $this->fault('admit'); return $reply;
    }
    public function status(AdmissionRequest $request): Admission
    {
        $this->f->usage->db->assertIdle();
        $this->f->rig->sql('INSERT INTO host_lookups VALUES (:tenant,:key,\'admit\')',['tenant'=>$request->operationKey->tenant->value,'key'=>$request->operationKey->value]);
        return $this->f->rig->budget->status($request);
    }
    public function settle(Ref $reservation,Ref $usageEvent): void { $this->write($reservation,'settle',$usageEvent->value); }
    public function release(Ref $reservation,string $provenNotSentReason): void { $this->write($reservation,'release',$provenNotSentReason); }
    public function renew(Ref $reservation,UtcInstant $deadline): Admission
    { $this->write($reservation,'renew',$deadline->value); return $this->renewed($reservation,$deadline->value); }
    public function lookup(Operation $operation): OwnerResolution
    {
        $this->f->usage->db->assertIdle();
        $this->f->rig->sql('INSERT INTO host_lookups VALUES (:tenant,:key,:kind)',
            ['tenant'=>$operation->id->tenant->value,'key'=>$operation->request->operationKey->value,'kind'=>$operation->kind]);
        $payload=$operation->payload['usage_event_ref'] ?? $operation->payload['reason'] ?? $operation->payload['deadline'];
        $applied=$this->f->rig->sql('SELECT 1 FROM host_accounting WHERE tenant=:tenant AND reservation=:reservation AND kind=:kind AND payload=:payload',
            ['tenant'=>$operation->id->tenant->value,'reservation'=>$operation->reservation->value,'kind'=>$operation->kind,'payload'=>$payload])->fetchColumn()!==false;
        $state=$this->lookupState==='unknown'?'unknown':($applied?'applied':'not_applied');
        return new OwnerResolution($operation->id,$operation->hash,$state,$state==='unknown'?null:$this->f->ref('owner-proof'),
            $operation->kind==='renew' && $applied?$this->renewed($operation->reservation,$payload):null);
    }
    private function renewed(Ref $reservation,string $deadline): Admission
    { return new Admission('admitted',$this->f->ref('renewed-grant'),$reservation,new UtcInstant($deadline),'owner-renew-1'); }
    private function write(Ref $reservation,string $kind,string $payload): void
    {
        $this->f->usage->db->assertIdle();
        $this->f->rig->sql('INSERT INTO host_accounting(tenant,reservation,kind,payload) VALUES (:tenant,:reservation,:kind,:payload)
            ON CONFLICT (tenant,reservation,kind,payload) DO UPDATE SET calls=host_accounting.calls+1',
            ['tenant'=>$reservation->tenant->value,'reservation'=>$reservation->value,'kind'=>$kind,'payload'=>$payload]);
        $this->fault($kind);
    }
    private function fault(string $kind): void { if ($this->lost===$kind) { throw new \RuntimeException('secret provider prompt credential child text'); } }
}
