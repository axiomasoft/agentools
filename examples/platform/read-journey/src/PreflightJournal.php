<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenToolsPlatform\Core\Budget\{Admission, AdmissionRequest};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref};
use AgenToolsPlatform\Core\Models\TransportPreflight;
use AgenToolsPlatform\Core\Runtime\{AdvanceLease, ExchangeResult, InvocationCheckpoint, InvocationJournal, InvocationPlan, InvocationRefusal, SavedProposal};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Example-only pre-IO policy checkpoint. Never reclassifies an ambiguous or accepted exchange.
 * The real journal commits the intent first. Known denial is thrown outside Runtime's transport
 * catch, with durable same-request not_sent evidence. A crash without that evidence stays unknown.
 */
final class PreflightJournal implements InvocationJournal
{
    private ?InvocationCheckpoint $resumable = null;
    public function __construct(private readonly SupportJourney $journey, private readonly InvocationJournal $inner) {}

    public function open(ExecutionContext $context, AdvanceLease $lease, InvocationPlan $plan, AdmissionRequest $admission): InvocationCheckpoint
    {
        $saved=$this->inner->open($context,$lease,$plan,$admission);$this->resumable=null;
        if($saved->stage()==='outbound'){
            $j=$this->journey;
            $proof=$j->db->row('SELECT request_hash FROM '.$j->db->table('demo_not_sent').' WHERE tenant_id=:tenant AND invocation_id=:id',
                ['tenant'=>$plan->runId->tenant->value,'id'=>$plan->request->invocationId->value]);
            if($proof!==null){
                if(Connection::text($proof,'request_hash')!==$plan->requestHash->value){throw new IntegrityConflict('Not-sent request binding conflict');}
                $this->resumable=$saved;
                // Presentation only: outbound() returns the original authoritative checkpoint,
                // without inserting another intent or changing its identity/admission/counters.
                $data=$saved->data;$data['stage']='admitted';return new InvocationCheckpoint($saved->lease,$data);
            }
        }
        return $saved;
    }
    public function admitted(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, Admission $admission): InvocationCheckpoint
    {return $this->inner->admitted($context,$plan,$saved,$admission);}
    public function outbound(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved): InvocationCheckpoint
    {
        $j=$this->journey;$outbound=$this->resumable??$this->inner->outbound($context,$plan,$saved);
        try{
            $j->boundary('intent'); // Committed intent, but transport has not been entered.
            $j->guard->assertCurrent($context,$plan);TransportPreflight::check($plan->request,$plan->deployment,$j->guard->now());
            if($plan->stepKey==='final-answer'){$j->owner->saved();} // Current disclosure, before provider IO.
        }catch(ContractViolation $denial){
            $j->db->transaction(function()use($j,$plan,$outbound,$denial):void{
                $this->fence($outbound);
                $j->db->execute('INSERT INTO '.$j->db->table('demo_not_sent').'(tenant_id,invocation_id,request_hash,denial_code,claim_epoch)
                    VALUES (:tenant,:id,:hash,:denial,:epoch)
                    ON CONFLICT (tenant_id,invocation_id) DO UPDATE SET denial_code=EXCLUDED.denial_code,claim_epoch=EXCLUDED.claim_epoch
                    WHERE '.$j->db->table('demo_not_sent').'.request_hash=EXCLUDED.request_hash',
                    ['tenant'=>$plan->runId->tenant->value,'id'=>$plan->request->invocationId->value,'hash'=>$plan->requestHash->value,
                        'denial'=>$denial->errorCode->value,'epoch'=>$outbound->lease->epoch]);
            });
            throw $denial;
        }
        $j->db->transaction(function()use($j,$plan,$outbound):void{
            $this->fence($outbound);
            $j->db->execute('DELETE FROM '.$j->db->table('demo_not_sent').' WHERE tenant_id=:tenant AND invocation_id=:id AND request_hash=:hash',
                ['tenant'=>$plan->runId->tenant->value,'id'=>$plan->request->invocationId->value,'hash'=>$plan->requestHash->value]);
        });
        $this->resumable=null;return $outbound;
    }
    private function fence(InvocationCheckpoint $saved):void
    {
        $j=$this->journey;$lease=$saved->lease;
        $row=$j->db->row('SELECT 1 FROM '.$j->db->table('runs').' WHERE tenant_id=:tenant AND id=:id AND version=:version
            AND fencing_epoch=:epoch AND claim_owner=:owner AND lease_until>clock_timestamp() FOR UPDATE',
            ['tenant'=>$lease->runId->tenant->value,'id'=>$lease->runId->value,'version'=>$lease->version,'epoch'=>$lease->epoch,'owner'=>$lease->owner]);
        if($row===null){throw new ContractViolation(ErrorCode::StaleClaim);}
    }
    public function refused(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, InvocationRefusal $refusal): InvocationCheckpoint
    {return $this->inner->refused($context,$plan,$saved,$refusal);}
    public function result(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, Ref $payload, PayloadHash $hash, ExchangeResult $result, ?Ref $resolutionEvidence=null): InvocationCheckpoint
    {return $this->inner->result($context,$plan,$saved,$payload,$hash,$result,$resolutionEvidence);}
    public function submitted(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, SavedProposal $proposal): InvocationCheckpoint
    {return $this->inner->submitted($context,$plan,$saved,$proposal);}
    public function finish(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved): InvocationCheckpoint
    {return $this->inner->finish($context,$plan,$saved);}
    public function actionsComplete(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved): InvocationCheckpoint
    {return $this->inner->actionsComplete($context,$plan,$saved);}
}
