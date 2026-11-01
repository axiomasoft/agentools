<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenToolsPlatform\Core\Common\{IntegrityConflict, PayloadHash, Ref};
use AgenToolsPlatform\Core\Models\{ContentPart, Deployment, FinishReason, JsonObject, ModelRequest, ModelResponse, ModelTransport, ModelUsage, ToolProposal};
use AgenToolsPlatform\Core\Runtime\{InvocationPlan, ProviderLookup, ProviderOutcome};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** One scripted exchange; never executes tools, retries or hides provider replay attempts. */
final readonly class ScriptedModel implements ModelTransport, ProviderLookup
{
    public function __construct(private SupportJourney $journey) {}
    public function generate(ModelRequest $request, Deployment $deployment): ModelResponse
    {
        $j=$this->journey;$j->idle();$plan=$j->activePlan??throw new \RuntimeException('Missing compiled plan');
        $row=$j->db->row('SELECT request_hash,admission_ref FROM '.$j->db->table('invocation_intents').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$j->tenant->value,'id'=>$request->invocationId->value]);
        if($row===null || Connection::text($row,'request_hash')!==substr($plan->requestHash->value,8)){throw new \RuntimeException('Provider IO before committed intent');}
        if(str_contains(json_encode($request->messages,JSON_THROW_ON_ERROR),'PRIVATE-OWNER-NOTE')){throw new \RuntimeException('Raw owner row reached model');}
        foreach($request->tools as $tool){if($tool->name!=='support_read'){throw new \RuntimeException('Tool allowlist bypass');}}
        $j->db->execute('INSERT INTO '.$j->db->table('demo_transport_calls').'(tenant_id,invocation_id) VALUES (:tenant,:id)', ['tenant'=>$j->tenant->value,'id'=>$request->invocationId->value]);
        $response=$this->response($plan);$json=json_encode($response,JSON_THROW_ON_ERROR);$resultRef=$j->ref('provider-response:'.$request->invocationId->value);
        $j->vault->put($j->context,$resultRef,new PayloadHash('sha-256:'.hash('sha256',$json)),$json);
        $j->db->execute('INSERT INTO '.$j->db->table('demo_provider').'(tenant_id,id,request_hash,request_ref,response_ref) VALUES (:tenant,:id,:hash,:request,:response)',
            ['tenant'=>$j->tenant->value,'id'=>$request->invocationId->value,'hash'=>$plan->requestHash->value,'request'=>$plan->requestRef->value,'response'=>$resultRef->value]);
        $j->boundary('provider');return $response;
    }
    private function response(InvocationPlan $plan): ModelResponse
    {
        $first=$plan->stepKey==='read-proposal';$proposal=$first && $plan->request->tools!==[];
        $text=$proposal?'Read the authorized ticket status.':'No permitted support tool.';
        if(!$first){
            // Provider consumes the immutable authorized model input, not a fresh host read.
            $prepared=$plan->request->messages[0]->parts[0]->text??'';
            if(preg_match('/\[evidence:owner\]\nTicket ST-17 status: (open|closed)\./',$prepared,$match)!==1){throw new \InvalidArgumentException('Missing prepared read result');}
            $text='Your ticket ST-17 is '.$match[1].'.';
        }
        $parts=[ContentPart::text($text)];
        return new ModelResponse($plan->request->invocationId,$plan->deployment,$plan->deployment,'scripted:'.$plan->request->invocationId->value,
            $proposal?FinishReason::ToolProposal:FinishReason::Completed,$parts,
            $proposal?[new ToolProposal($plan->request->invocationId,'support-proposal-1','support_read',new JsonObject(['ticket_ref'=>'ST-17']))]:[],
            usage:new ModelUsage(120,20,null,null),adapterVersion:'scripted-support/v1');
    }
    public function find(ExecutionContext $context, InvocationPlan $plan): ?ProviderOutcome
    {
        $j=$this->journey;$j->idle();$row=$j->db->row('SELECT request_hash,response_ref FROM '.$j->db->table('demo_provider').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$context->session->tenant->value,'id'=>$plan->request->invocationId->value]);
        if($row===null){return null;}if(Connection::text($row,'request_hash')!==$plan->requestHash->value){throw new IntegrityConflict('Unbound provider lookup');}
        return new ProviderOutcome($this->savedResponse($plan,Connection::text($row,'response_ref')),$plan->requestHash,new Ref($j->tenant,'lookup:'.$plan->request->invocationId->value));
    }
    private function savedResponse(InvocationPlan $plan,string $ref):ModelResponse
    {
        $j=$this->journey;$data=json_decode($j->vault->get($j->context,$j->ref($ref)),true,128,JSON_THROW_ON_ERROR);
        if(!is_array($data) || !is_array($data['parts']??null) || !is_array($data['proposals']??null) || !is_array($data['usage']??null)){
            throw new IntegrityConflict('Missing original provider outcome');
        }
        $identity=$data['invocationId']??null;$tenant=is_array($identity)?($identity['tenant']??null):null;
        if(!is_array($identity) || !is_array($tenant) || ($identity['value']??null)!==$plan->request->invocationId->value || ($tenant['value']??null)!==$j->tenant->value){
            throw new IntegrityConflict('Provider outcome identity conflict');
        }
        $parts=[];foreach($data['parts'] as $part){
            if(!is_array($part) || ($part['kind']??null)!=='text' || !is_string($part['text']??null)){throw new IntegrityConflict('Unsupported scripted provider part');}
            $parts[]=ContentPart::text($part['text']);
        }
        $proposals=[];foreach($data['proposals'] as $proposal){
            $arguments=is_array($proposal)?($proposal['arguments']??null):null;
            if(!is_array($proposal) || !is_array($arguments) || !is_array($arguments['value']??null)){
                throw new IntegrityConflict('Missing original provider proposal');
            }
            $proposals[]=new ToolProposal($plan->request->invocationId,$this->text($proposal,'providerProposalRef'),$this->text($proposal,'tool'),new JsonObject($arguments['value']));
        }
        $usage=$data['usage'];$units=[];foreach(['inputTokens','outputTokens','cacheReadTokens','cacheWriteTokens'] as $field){
            if(!array_key_exists($field,$usage) || ($usage[$field]!==null && !is_int($usage[$field]))){throw new IntegrityConflict('Missing is not zero provider usage');}
            $units[]=$usage[$field];
        }
        return new ModelResponse($plan->request->invocationId,$plan->deployment,$plan->deployment,$this->text($data,'providerRequestId'),
            FinishReason::from($this->text($data,'finishReason')),$parts,$proposals,usage:new ModelUsage($units[0],$units[1],$units[2],$units[3]),adapterVersion:$this->text($data,'adapterVersion'));
    }
    /** @param array<array-key,mixed> $data */
    private function text(array $data,string $field):string
    {
        $value=$data[$field]??null;if(!is_string($value)){throw new IntegrityConflict('Invalid saved provider metadata');}return $value;
    }
    public function stream(ModelRequest $request, Deployment $deployment): iterable { throw new \LogicException('This example owns no streaming/effect loop'); }
}
