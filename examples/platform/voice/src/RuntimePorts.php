<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Voice;

use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Actions\{ActionAccess, ActionPolicy, ActionRequest, ActionSubmission, ActionView};
use AgenToolsPlatform\Core\Budget\{Admission, AdmissionRequest, BudgetPort};
use AgenToolsPlatform\Core\Common\{CanonicalJson, ContractViolation, ErrorCode, IntegrityConflict, Ref, UtcInstant};
use AgenToolsPlatform\Core\Models\{CancellationSignal, ContentPart, Deployment, FinishReason, JsonObject, ModelRequest, ModelResponse, ModelTransport, ModelUsage, ToolProposal};
use AgenToolsPlatform\Core\Runtime\{InvocationGuard, InvocationPlan, ProposalDispatcher, SavedProposal};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\Connection as C;

final readonly class Json implements CanonicalJson
{
    public function canonicalize(mixed $value): string { return (new Canonicalizer())->canonicalize($value); }
    public function canonicalizeJson(string $json): string { return (new Canonicalizer())->canonicalizeJson($json); }
}
final readonly class Policy implements ActionPolicy
{
    public function __construct(private Journey $j) {}
    public function current(ExecutionContext $context): ActionAccess
    {
        $context->assertCurrent($this->j->host);$keys=[ActionAccess::key($this->j->definition->ref)];
        return new ActionAccess($keys,$keys,$keys,$keys,$keys,['voice.example.read']);
    }
}
final readonly class Guard implements InvocationGuard,CancellationSignal
{
    public function __construct(private Journey $j) {}
    public function now(): UtcInstant { return $this->j->host->now(); }
    public function isCancellationRequested(): bool { return false; }
    public function assertCurrent(ExecutionContext $context,InvocationPlan $plan): void
    {
        $context->assertCurrent($this->j->host);$row=$this->j->db->row('SELECT context_revision FROM '.$this->j->db->table('voice_demo_snapshots').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$context->session->tenant->value,'id'=>$plan->snapshot->ref->value]);
        if ($row===null || C::number($row,'context_revision')!==$this->j->host->revision()) { throw new ContractViolation(ErrorCode::StaleContext); }
    }
}
final readonly class Budget implements BudgetPort
{
    public function __construct(private Journey $j) {}
    public function status(AdmissionRequest $request): Admission
    {
        $this->j->db->assertIdle();$r=$this->j->db->row('SELECT hash FROM '.$this->j->db->table('voice_demo_admissions').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$request->operationKey->tenant->value,'id'=>$request->operationKey->value]);
        if ($r===null) { return new Admission('not_found'); }
        if (C::text($r,'hash')!==$request->hash->value) { throw new IntegrityConflict('Example admission conflict'); }
        return new Admission('admitted',$this->j->ref('grant:'.$request->operationKey->value),$this->j->ref('reservation:'.$request->operationKey->value),new UtcInstant('2030-01-01T00:00:00Z'),'scripted-voice/v1',['units'=>8192]);
    }
    public function admit(AdmissionRequest $request): Admission
    {
        if ($this->j->count('voice_demo_admissions')>=16) { return new Admission('denied'); }
        $this->j->db->execute('INSERT INTO '.$this->j->db->table('voice_demo_admissions').' VALUES(:tenant,:id,:hash) ON CONFLICT DO NOTHING',
            ['tenant'=>$request->operationKey->tenant->value,'id'=>$request->operationKey->value,'hash'=>$request->hash->value]);return $this->status($request);
    }
    public function settle(Ref $reservation,Ref $usageEvent): void { throw new \LogicException('External metering owner not qualified by this example'); }
    public function release(Ref $reservation,string $provenNotSentReason): void { throw new \LogicException('No automatic release'); }
    public function renew(Ref $reservation,UtcInstant $deadline): Admission { return new Admission('unavailable'); }
}
final readonly class Planner implements ModelTransport
{
    public function __construct(private Journey $j) {}
    public function generate(ModelRequest $request,Deployment $deployment): ModelResponse
    {
        $this->j->db->assertIdle();$row=$this->j->db->row('INSERT INTO '.$this->j->db->table('voice_demo_provider').' AS p (tenant_id,id,request_hash,deployment_ref) VALUES(:tenant,:id,:hash,:deployment)
            ON CONFLICT(tenant_id,id) DO UPDATE SET attempts=p.attempts+1 RETURNING attempts',
            ['tenant'=>$request->invocationId->tenant->value,'id'=>$request->invocationId->value,'hash'=>$this->j->plan->requestHash->value,'deployment'=>$deployment->ref->value]);
        if ($row===null || C::number($row,'attempts')!==1) { throw new IntegrityConflict('Repeated model transport attempt detected'); }
        return new ModelResponse($request->invocationId,$deployment,$deployment,'scripted:'.$request->invocationId->value,
            $this->j->propose?FinishReason::ToolProposal:FinishReason::Completed,
            [ContentPart::text('Первый.'),ContentPart::text('Второй.'),ContentPart::text('Третий.')],
            $this->j->propose?[new ToolProposal($request->invocationId,'voice-read-1','voice_read',new JsonObject(['query'=>'fixture']))]:[],
            usage:new ModelUsage(40,12,null,null),adapterVersion:'scripted-voice/v1');
    }
    public function stream(ModelRequest $request,Deployment $deployment): iterable { throw new \LogicException('No parallel voice model loop'); }
}
final readonly class Actions implements ProposalDispatcher
{
    public function __construct(private Journey $j) {}
    public function submit(ExecutionContext $context,SavedProposal $proposal): ActionView
    {
        $input=$this->j->ref('action-input:'.$proposal->id->value);
        $this->j->vault->put($context,$input,new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:'.hash('sha256',$proposal->argumentsJson)),$proposal->argumentsJson);
        return $this->j->actions->submit(new ActionSubmission(new ActionRequest($proposal->definition,$this->j->ref('origin:'.$this->j->accepted->runId->value),
            $proposal->id,$this->j->ref($proposal->id->value),$input,$proposal->inputHash,$context,$proposal->deadline,$this->j->hasher),
            $proposal->argumentsJson,$this->j->hasher,new Json()))->view;
    }
    public function status(ExecutionContext $context,SavedProposal $proposal): ActionView { return $this->j->actions->status($proposal->actionId,$context); }
}
