<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenTools\Action\{ActionReducer, Actor, FoldedAction, JournalHashChain};
use AgenTools\Persistence\UncommittedEvent;
use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Actions\{ActionLifecycle, ActionRegistration, ActionSubmission, ActionView, CancelRequest, ReconcileRequest};
use AgenToolsPlatform\Core\Common\{ActionId, ActionReceipt, ContractViolation, ErrorCode, IntegrityConflict};
use AgenToolsPlatform\Core\Runtime\SavedProposal;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Actions\ActionRepository;
use AgenToolsPlatform\Postgres\Claims\RunClaim;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RunRepository};

/** Reuses ActionRepository/EventStore/ActionReducer; no second reducer or effect-capable loop. */
final readonly class ReadLifecycle implements ActionLifecycle
{
    public function __construct(private SupportJourney $journey) {}
    public function submit(ActionSubmission $submission, ActionRegistration $registration): ActionView
    {
        $j=$this->journey;$j->idle();$request=$submission->request;
        $map=$j->db->row('SELECT action_ref,payload_hash FROM '.$j->db->table('proposal_map').' WHERE tenant_id=:tenant AND id=:id AND run_id=:run',
            ['tenant'=>$j->tenant->value,'id'=>$request->proposalId?->value,'run'=>$j->runId->value]);
        if($map===null || Connection::text($map,'payload_hash')!==substr($request->inputHash->value,8)){throw new IntegrityConflict('Missing accepted proposal binding');}
        $id=new ActionId($j->tenant,Connection::text($map,'action_ref'));
        $j->db->execute('INSERT INTO '.$j->db->table('demo_submissions').'(tenant_id,id,request_hash,action_id) VALUES (:tenant,:id,:hash,:action) ON CONFLICT DO NOTHING',
            ['tenant'=>$j->tenant->value,'id'=>$request->idempotencyKey->value,'hash'=>$request->requestHash->value,'action'=>$id->value]);
        $saved=$j->db->row('SELECT request_hash,action_id FROM '.$j->db->table('demo_submissions').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$j->tenant->value,'id'=>$request->idempotencyKey->value]);
        if($saved===null || Connection::text($saved,'request_hash')!==$request->requestHash->value || Connection::text($saved,'action_id')!==$id->value){throw new IntegrityConflict('Action submission identity conflict');}
        if($this->state($id)->seq===0){$this->append($id,'action_created');}
        return $this->status($id,$request->context);
    }
    public function status(ActionId $id, ExecutionContext $context): ActionView
    {
        $j=$this->journey;$context->session->tenant->assertSame($id->tenant);$context->assertCurrent($j->host);
        $j->registry->executionView($j->readDefinition->ref,$j->policy->current($context));
        $bound=$j->db->row('SELECT 1 FROM '.$j->db->table('proposal_map').' WHERE tenant_id=:tenant AND action_ref=:id AND run_id=:run',
            ['tenant'=>$id->tenant->value,'id'=>$id->value,'run'=>$j->runId->value]);
        if($bound===null){throw new ContractViolation(ErrorCode::AccessDenied);}
        $state=$this->state($id);
        return new ActionView(new ActionReceipt($id,$state->status->value??throw new \RuntimeException('Action not accepted'),$state->seq),$j->readDefinition->ref,'status');
    }
    private function state(ActionId $id): FoldedAction
    {
        $j=$this->journey;$j->idle();$page=$j->db->journal()->load($id->tenant->value,$id->value,0,16);
        if($page->hasMore){throw new \RuntimeException('Read Action event bound exceeded');}
        return (new ActionReducer(hashChain:new JournalHashChain(new Canonicalizer())))->fold($page->events);
    }
    private function append(ActionId $id,string $kind): void
    {
        $j=$this->journey;$j->idle();$lease=$j->workerLease??throw new ContractViolation(ErrorCode::StaleClaim);
        $run=(new RunRepository($j->environment))->get($j->context,$j->runId)??throw new ContractViolation(ErrorCode::AccessDenied);
        $owner=$j->db->row('SELECT claim_owner,lease_until FROM '.$j->db->table('runs').' WHERE tenant_id=:tenant AND id=:id AND fencing_epoch=:epoch',
            ['tenant'=>$j->tenant->value,'id'=>$j->runId->value,'epoch'=>$lease->epoch]);
        if($owner===null || $owner['claim_owner']!==$lease->owner){throw new ContractViolation(ErrorCode::StaleClaim);}
        $claim=new RunClaim($run,$lease->owner,Connection::text($owner,'lease_until'));
        $eventId=substr($j->hasher->hash($j->tenant,'read-action-event',[$id->value,$kind])->value,8);
        $event=new UncommittedEvent($eventId,$kind,1,'2026-09',new Actor('host','support-owner'),$j->guard->now()->value,[]);
        (new ActionRepository($j->environment))->append($j->context,($j->activePlan??throw new \RuntimeException('Missing snapshot'))->snapshot,$claim,$id,$this->state($id)->seq,[$event]);
    }
    public function performRead(SavedProposal $proposal): void
    {
        $j=$this->journey;$registration=$j->registry->executionView($proposal->definition,$j->policy->current($j->context));
        if($registration->definition->effect!==\AgenToolsPlatform\Core\Actions\EffectClass::Read || $registration->definition->costBoundMinor!==1
            || $registration->definition->redaction!=='minimal-status'){throw new ContractViolation(ErrorCode::PolicyConflict);}
        $state=$this->status($proposal->actionId,$j->context);
        if($state->receipt->wireStatus==='completed'){return;}
        if($state->receipt->wireStatus==='created'){$this->append($proposal->actionId,'action_started');}
        $lease=$j->workerLease??throw new ContractViolation(ErrorCode::StaleClaim);
        $current=$j->db->row('SELECT 1 FROM '.$j->db->table('runs').' WHERE tenant_id=:tenant AND id=:run AND claim_owner=:owner AND fencing_epoch=:epoch AND lease_until>clock_timestamp()',
            ['tenant'=>$j->tenant->value,'run'=>$j->runId->value,'owner'=>$lease->owner,'epoch'=>$lease->epoch]);
        if($current===null){throw new ContractViolation(ErrorCode::StaleClaim);}
        $this->journey->owner->read($proposal); // Current owner ACL/disclosure outside the committed journal transaction.
        $this->append($proposal->actionId,'action_completed');$j->boundary('read-complete');
    }
    public function cancel(ActionId $id, CancelRequest $request): ActionView { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
    public function reconcile(ActionId $id, ReconcileRequest $request): ActionView { throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
}
