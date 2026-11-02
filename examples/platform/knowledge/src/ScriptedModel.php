<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Knowledge;

use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Core\Models\{ContentPart,Deployment,FinishReason,ModelRequest,ModelResponse,ModelTransport,ModelUsage};
use AgenToolsPlatform\Core\Runtime\{InvocationPlan,ProviderLookup,ProviderOutcome};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Knowledge\Wire;

/** Deterministic citation echo over selected immutable evidence. No real model/provider claim. */
final readonly class ScriptedModel implements ModelTransport,ProviderLookup
{
    public function __construct(private KnowledgeJourney $journey) {}
    public function generate(ModelRequest $request, Deployment $deployment): ModelResponse
    {
        $j=$this->journey;$h=$j->host;$h->idle();$plan=$j->invocation ?? throw new \LogicException('Prepare invocation first');
        $row=$h->db->row('SELECT request_hash FROM '.$h->db->table('invocation_intents').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$h->tenant->value,'id'=>$request->invocationId->value]);
        if ($row===null || $row['request_hash']!==substr($plan->requestHash->value,8)) { throw new IntegrityConflict('Provider IO before committed intent'); }
        (new RequestView($j))->renderRequest($request,$deployment);
        $fragments=$j->selectedEvidence();
        $body=['status'=>$fragments===[] ? 'no_evidence' : 'evidence','retrieval_status'=>$j->evidence?->status,
            'answer'=>$fragments===[] ? 'No permitted evidence is available; ask for clarification.' : implode("\n",array_column($fragments,'text')),
            'citations'=>array_column($fragments,'citation')];
        $json=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $h->db->execute('INSERT INTO '.$h->db->table('knowledge_demo_calls').' VALUES (:tenant,:id,:hash,:response)',
            ['tenant'=>$h->tenant->value,'id'=>$request->invocationId->value,'hash'=>$plan->requestHash->value,'response'=>$json]);
        $h->boundary('knowledge-provider');
        return $this->response($plan,$json);
    }
    public function find(ExecutionContext $context, InvocationPlan $plan): ?ProviderOutcome
    {
        $h=$this->journey->host;$h->idle();$context->session->tenant->assertSame($h->tenant);
        $row=$h->db->row('SELECT request_hash,response_json FROM '.$h->db->table('knowledge_demo_calls').' WHERE tenant=:tenant AND invocation_id=:id',
            ['tenant'=>$h->tenant->value,'id'=>$plan->request->invocationId->value]);
        if ($row===null) { return null; }
        if ($row['request_hash']!==$plan->requestHash->value) { throw new IntegrityConflict('Knowledge provider lookup conflict'); }
        return new ProviderOutcome($this->response($plan,Wire::string($row,'response_json')),$plan->requestHash,$h->ref('knowledge:lookup:'.$plan->request->invocationId->value));
    }
    private function response(InvocationPlan $plan, string $json): ModelResponse
    {
        return new ModelResponse($plan->request->invocationId,$plan->deployment,$plan->deployment,'scripted-knowledge:'.$plan->request->invocationId->value,
            FinishReason::Completed,[ContentPart::text($json)],[],usage:new ModelUsage(180,60,null,null),adapterVersion:'synthetic-knowledge/v1');
    }
    public function stream(ModelRequest $request, Deployment $deployment): iterable { throw new \LogicException('No streaming or parallel effect loop'); }
}
