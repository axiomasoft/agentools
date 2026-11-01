<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenToolsPlatform\Core\Actions\{ActionApplication, ActionRequest, ActionSubmission, ActionView};
use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Runtime\{ExchangeResult, ProposalDispatcher, SavedProposal};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Stable mapping bridge to the real guarded facade and dated durable journal. Submit only accepts. */
final readonly class ReadActions implements ProposalDispatcher
{
    public ReadLifecycle $lifecycle;
    private ActionApplication $application;
    public function __construct(private SupportJourney $journey)
    {
        $this->lifecycle=new ReadLifecycle($journey);
        $this->application=new ActionApplication($journey->registry,$journey->policy,$journey->host,new InputSchema($journey->readDefinition->inputSchema),$this->lifecycle);
    }
    public function submit(ExecutionContext $context, SavedProposal $proposal): ActionView
    {
        $j=$this->journey;$j->boundary('proposal');$input=$j->ref('action-input:'.$proposal->id->value);
        $j->vault->put($context,$input,new PayloadHash('sha-256:'.hash('sha256',$proposal->argumentsJson)),$proposal->argumentsJson);
        $request=new ActionRequest($proposal->definition,$j->ref('origin:'.$j->runId->value),$proposal->id,$j->ref($proposal->id->value),$input,$proposal->inputHash,$context,$proposal->deadline,$j->hasher);
        $view=$this->application->submit(new ActionSubmission($request,$proposal->argumentsJson,$j->hasher,new Jcs()))->view;
        $proposal->actionId->assertSame($view->receipt->actionId);$j->boundary('action');return $view;
    }
    public function status(ExecutionContext $context, SavedProposal $proposal): ActionView { return $this->application->status($proposal->actionId,$context); }
    public function performRead(): void
    {
        $j=$this->journey;$plan=$j->activePlan??throw new \RuntimeException('Missing plan');
        $row=$j->db->row('SELECT proposal_ref FROM '.$j->db->table('proposal_map').' WHERE tenant_id=:tenant AND run_id=:run', ['tenant'=>$j->tenant->value,'run'=>$j->runId->value]);
        if($row===null){throw new \RuntimeException('No durable mapping');}
        $result=ExchangeResult::decode($plan,$j->vault->get($j->context,$j->ref(Connection::text($row,'proposal_ref'))),$j->hasher);
        if(count($result->proposals)!==1){throw new \RuntimeException('Read fixture requires one mapped proposal');}
        $this->lifecycle->performRead($result->proposals[0]);
    }
}
