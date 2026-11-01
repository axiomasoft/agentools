<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenToolsPlatform\Core\Budget\{Admission, AdmissionRequest, BudgetPort};
use AgenToolsPlatform\Core\Common\{IntegrityConflict, Ref, UtcInstant};
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Finite scripted external admission owner, not a business ledger or unlimited fallback. */
final readonly class Budget implements BudgetPort
{
    public function __construct(private SupportJourney $journey) {}
    public function status(AdmissionRequest $request): Admission
    {
        $j=$this->journey;$j->idle();
        $policy=$j->db->row('SELECT budget_state FROM '.$j->db->table('demo_permissions').' WHERE tenant_id=:tenant',['tenant'=>$request->operationKey->tenant->value]);
        if($policy===null || $policy['budget_state']!=='admitted'){return new Admission(is_string($policy['budget_state']??null)?$policy['budget_state']:'unavailable');}
        $row=$j->db->row('SELECT hash FROM '.$j->db->table('demo_admissions').' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$request->operationKey->tenant->value,'id'=>$request->operationKey->value]);
        if($row===null){return new Admission('not_found');}
        if(Connection::text($row,'hash')!==$request->hash->value){throw new IntegrityConflict('ADMISSION_PAYLOAD_CONFLICT');}
        return new Admission('admitted',$j->ref('grant:'.$request->operationKey->value),$j->ref('reserve:'.$request->operationKey->value),new UtcInstant('2030-01-02T00:00:00Z'),'scripted-owner/v1',['units'=>4096]);
    }
    public function admit(AdmissionRequest $request): Admission
    {
        $j=$this->journey;$j->idle();$status=$this->status($request);
        if($status->status!=='not_found'){return $status;}
        if($j->count('demo_admissions')>=4){return new Admission('denied',reason:'bounded-owner');}
        $j->db->execute('INSERT INTO '.$j->db->table('demo_admissions').'(tenant_id,id,hash) VALUES (:tenant,:id,:hash) ON CONFLICT DO NOTHING',
            ['tenant'=>$request->operationKey->tenant->value,'id'=>$request->operationKey->value,'hash'=>$request->hash->value]);
        return $this->status($request);
    }
    public function settle(Ref $reservation, Ref $usageEvent): void { throw new \LogicException('Settlement is owner/P9 scope'); }
    public function release(Ref $reservation, string $provenNotSentReason): void { throw new \LogicException('No automatic reservation release'); }
    public function renew(Ref $reservation, UtcInstant $deadline): Admission { return new Admission('unavailable'); }
}
