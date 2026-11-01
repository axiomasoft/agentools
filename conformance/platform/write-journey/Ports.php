<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Conformance\WriteJourney;

use AgenToolsPlatform\Core\Actions\{ActionAccess, ActionPolicy, ActionRequest, ActionSubmission, ActionView};
use AgenToolsPlatform\Core\Actions\Effects\{LookupResult, LookupStatus, OwnerPort, Request, Strategy};
use AgenToolsPlatform\Core\Budget\{Admission, AdmissionRequest, BudgetPort};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref, UtcInstant};
use AgenToolsPlatform\Core\Models\{Deployment, ModelRequest, ModelResponse, ModelTransport};
use AgenToolsPlatform\Core\Runtime\{InvocationGuard, InvocationPayloads, InvocationPlan, ProposalDispatcher, ProviderLookup, ProviderOutcome, SavedProposal};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Examples\DirectAction\Jcs;
use AgenToolsPlatform\Postgres\Runtime\Connection;

final readonly class Policy implements ActionPolicy, InvocationGuard
{
    public function __construct(private Journey $j) {}
    public function current(ExecutionContext $context): ActionAccess
    {
        $this->j->current($context);
        $keys=[ActionAccess::key($this->j->composition->definition->ref)];
        return new ActionAccess($keys,$keys,$keys,$keys,$keys,['documents.write']);
    }
    public function now(): UtcInstant { return new UtcInstant(gmdate('Y-m-d\TH:i:s\Z')); }
    public function assertCurrent(ExecutionContext $context,InvocationPlan $plan): void { $this->j->current($context,$plan->stepKey==='response'); }
}

final readonly class Vault implements InvocationPayloads
{
    public function __construct(private Journey $j) {}
    public function put(ExecutionContext $context,Ref $key,PayloadHash $hash,string $json): void
    {
        $this->j->current($context); $key->tenant->assertSame($context->identity->tenant);
        if ('sha-256:'.hash('sha256',$json)!==$hash->value) { throw new IntegrityConflict('Host payload hash conflict'); }
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher=base64_encode($nonce.sodium_crypto_secretbox($json,$nonce,$this->j->vaultKey()));
        $db=$this->j->db;
        $db->execute('INSERT INTO '.$db->table('write_payloads').' VALUES (:tenant,:id,:hash,:cipher) ON CONFLICT DO NOTHING',
            ['tenant'=>$key->tenant->value,'id'=>$key->value,'hash'=>$hash->value,'cipher'=>$cipher]);
        if ($this->find($context,$key)!==$json) { throw new IntegrityConflict('Immutable host payload conflict'); }
        $db->execute('INSERT INTO '.$db->table('payload_refs').'
            (tenant_id,id,session_id,binding_hash,content_hash,encrypted_ref,encryption_key_ref,classification,purpose,retention_until)
            VALUES (:tenant,:id,:session,:binding,:hash,\'synthetic:encrypted\',\'synthetic:vault-key\',\'private\',\'document.write\',\'2031-01-01\') ON CONFLICT DO NOTHING',
            ['tenant'=>$key->tenant->value,'id'=>$key->value,'session'=>$context->session->value,
                'binding'=>substr($context->scopeHash($this->j->hasher)->value,8),'hash'=>substr($hash->value,8)]);
        if (str_ends_with($key->value,':accepted')) { $this->j->boundary('payload-accepted'); }
    }
    public function find(ExecutionContext $context,Ref $key): ?string
    {
        $this->j->current($context); $context->identity->tenant->assertSame($key->tenant);
        $row=$this->j->db->row('SELECT hash,ciphertext FROM '.$this->j->db->table('write_payloads').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$key->tenant->value,'id'=>$key->value]);
        if ($row===null) { return null; }
        $cipher=base64_decode(Connection::text($row,'ciphertext'),true);
        if ($cipher===false) { throw new IntegrityConflict('Invalid host ciphertext'); }
        $body=sodium_crypto_secretbox_open(substr($cipher,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($cipher,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$this->j->vaultKey());
        if ($body===false || 'sha-256:'.hash('sha256',$body)!==Connection::text($row,'hash')) { throw new IntegrityConflict('Host payload integrity conflict'); }
        return $body;
    }
    public function get(ExecutionContext $context,Ref $key): string { return $this->find($context,$key)??throw new IntegrityConflict('Missing host payload'); }
}

final readonly class Budget implements BudgetPort
{
    public function __construct(private Journey $j) {}
    public function status(AdmissionRequest $request): Admission
    {
        $this->j->idle();
        $row=$this->j->db->row('SELECT hash FROM '.$this->j->db->table('write_admissions').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$request->operationKey->tenant->value,'id'=>$request->operationKey->value]);
        if ($row===null) { return new Admission('not_found'); }
        if (Connection::text($row,'hash')!==$request->hash->value) { throw new IntegrityConflict('Admission request conflict'); }
        return new Admission('admitted',$this->j->ref('admission:'.$request->operationKey->value),$this->j->ref('reservation:'.$request->operationKey->value),new UtcInstant('2030-01-01T00:00:00Z'),'synthetic-owner-v1');
    }
    public function admit(AdmissionRequest $request): Admission
    {
        $this->j->current($this->j->context);
        $this->j->db->execute('INSERT INTO '.$this->j->db->table('write_admissions').' VALUES (:tenant,:id,:hash) ON CONFLICT DO NOTHING',
            ['tenant'=>$request->operationKey->tenant->value,'id'=>$request->operationKey->value,'hash'=>$request->hash->value]);
        $this->j->boundary('admission'); return $this->status($request);
    }
    public function settle(Ref $reservation,Ref $usageEvent): void { $this->j->idle(); }
    public function release(Ref $reservation,string $provenNotSentReason): void { throw new \LogicException('No implicit accounting release'); }
    public function renew(Ref $reservation,UtcInstant $deadline): Admission { throw new \LogicException('No implicit accounting renewal'); }
}

final readonly class Provider implements ModelTransport,ProviderLookup
{
    public function __construct(private Journey $j) {}
    public function generate(ModelRequest $request,Deployment $deployment): ModelResponse
    {
        $j=$this->j; $j->current($j->context); $plan=$j->activePlan??throw new \LogicException('Missing active plan');
        $observer=$j->observer();
        $intent=$observer->row('SELECT request_hash FROM '.$observer->table('invocation_intents').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$j->tenant->value,'id'=>$request->invocationId->value]);
        if ($intent===null || 'sha-256:'.Connection::text($intent,'request_hash')!==$plan->requestHash->value) { throw new \LogicException('Provider intent not committed'); }
        $j->boundary('invocation-intent');
        $body=(new Jcs())->canonicalize(['arguments'=>json_decode(Journey::INPUT,false,512,JSON_THROW_ON_ERROR),'response'=>$plan->stepKey==='response']);
        $ref=$j->ref('provider-response:'.$request->invocationId->value);
        $j->vault->put($j->context,$ref,new PayloadHash('sha-256:'.hash('sha256',$body)),$body);
        $j->db->execute('INSERT INTO '.$j->db->table('write_provider').' VALUES (:tenant,:id,:hash,:response)',
            ['tenant'=>$j->tenant->value,'id'=>$request->invocationId->value,'hash'=>$plan->requestHash->value,'response'=>$ref->value]);
        $j->boundary('provider-commit'); return $j->response($plan,$body);
    }
    public function find(ExecutionContext $context,InvocationPlan $plan): ?ProviderOutcome
    {
        $j=$this->j; $j->current($context);
        $row=$j->db->row('SELECT hash,response_ref FROM '.$j->db->table('write_provider').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$j->tenant->value,'id'=>$plan->request->invocationId->value]);
        if ($row===null) { return null; }
        if (Connection::text($row,'hash')!==$plan->requestHash->value) { throw new IntegrityConflict('Provider lookup hash conflict'); }
        return new ProviderOutcome($j->response($plan,$j->vault->get($context,$j->ref(Connection::text($row,'response_ref')))),
            $plan->requestHash,$j->ref('provider-lookup:'.$plan->request->invocationId->value));
    }
    public function stream(ModelRequest $request,Deployment $deployment): iterable { throw new \LogicException('No second effect loop'); }
}

final readonly class Dispatcher implements ProposalDispatcher
{
    public function __construct(private Journey $j) {}
    public function submit(ExecutionContext $context,SavedProposal $proposal): ActionView
    {
        $j=$this->j; $j->current($context);
        $submission=$j->submission($proposal);
        $j->vault->put($context,$submission->request->inputRef,new PayloadHash('sha-256:'.hash('sha256',$submission->inputJson)),$submission->inputJson);
        $view=$j->actions->submit($submission)->view; $proposal->actionId->assertSame($view->receipt->actionId);
        $j->boundary('action-submit');
        $j->confirmation->prepare($submission); $j->boundary('preview');
        return $j->actions->status($view->receipt->actionId,$context);
    }
    public function status(ExecutionContext $context,SavedProposal $proposal): ActionView { return $this->j->actions->status($proposal->actionId,$context); }
}

final readonly class Owner implements OwnerPort
{
    public function __construct(private Journey $j,private int $epoch) {}
    public function namespace(): string { return 'synthetic.documents'; }
    public function strategy(): Strategy { return Strategy::NativeKey; }
    public function execute(Request $request): LookupResult
    {
        $j=$this->j; $j->idle(); $j->boundary('effect-intent'); $db=$j->observer();
        $result=$db->transaction(function () use ($db,$request,$j): LookupResult {
            $permission=$db->row('SELECT allowed FROM '.$db->table('example_owner_permissions').' WHERE tenant_id=:tenant AND actor_ref=:actor FOR SHARE',
                ['tenant'=>$request->action->tenant->value,'actor'=>$request->context->identity->actorRef->value]);
            $control=$db->row('SELECT tool_allowed,emergency FROM '.$db->table('write_controls').' WHERE tenant_id=:tenant AND instance_id=:instance FOR SHARE',
                ['tenant'=>$request->action->tenant->value,'instance'=>$j->instance]);
            if ($permission===null || $permission['allowed']!==true || $control===null || $control['tool_allowed']!==true || $control['emergency']!==false) {
                return new LookupResult(LookupStatus::Failed,$j->ref($request->operationKey),$this->namespace(),$request->requestHash,$j->ref('denied:'.$request->operationKey));
            }
            $existing=$db->row('SELECT request_hash FROM '.$db->table('example_owner_writes').' WHERE tenant_id=:tenant AND operation_key=:key',
                ['tenant'=>$request->action->tenant->value,'key'=>$request->operationKey]);
            if ($existing!==null) {
                if (Connection::text($existing,'request_hash')!==$request->requestHash->value) { throw new IntegrityConflict('Owner key/hash conflict'); }
                return $this->receipt($request);
            }
            $intent=$db->row('SELECT request_hash,request_ref FROM '.$db->table('effect_intents').' WHERE tenant_id=:tenant AND action_ref=:action AND owner_operation_key=:key',
                ['tenant'=>$request->action->tenant->value,'action'=>$request->action->value,'key'=>$request->operationKey]);
            if ($intent===null || 'sha-256:'.Connection::text($intent,'request_hash')!==$request->requestHash->value || Connection::text($intent,'request_ref')!==$request->requestRef->value) {
                throw new IntegrityConflict('Owner requires committed immutable intent');
            }
            $state=$db->row('SELECT state FROM '.$db->table('action_current').' WHERE tenant_id=:tenant AND id=:id FOR UPDATE',
                ['tenant'=>$request->action->tenant->value,'id'=>$request->action->value]);
            $json=json_decode(Connection::text($state??throw new IntegrityConflict('Missing owner fence'),'state'),true,512,JSON_THROW_ON_ERROR);
            if (!is_array($json) || ($json['epoch']??null)!==$this->epoch || ($json['cancellation_requested']??true)) { throw new ContractViolation(ErrorCode::StaleClaim); }
            if ($db->row('SELECT id FROM '.$db->table('confirmation_previews').' WHERE tenant_id=:tenant AND action_ref=:action
                AND (binding->>\'expires_at\')::timestamptz>clock_timestamp()', ['tenant'=>$request->action->tenant->value,'action'=>$request->action->value])===null) { throw new ContractViolation(ErrorCode::ConfirmationInvalid); }
            $db->execute('INSERT INTO '.$db->table('example_owner_writes').' VALUES (:tenant,:key,:hash,CAST(:payload AS jsonb)) ON CONFLICT DO NOTHING',
                ['tenant'=>$request->action->tenant->value,'key'=>$request->operationKey,'hash'=>$request->requestHash->value,'payload'=>Journey::INPUT]);
            $saved=$db->row('SELECT request_hash FROM '.$db->table('example_owner_writes').' WHERE tenant_id=:tenant AND operation_key=:key',
                ['tenant'=>$request->action->tenant->value,'key'=>$request->operationKey]);
            if ($saved===null || Connection::text($saved,'request_hash')!==$request->requestHash->value) { throw new IntegrityConflict('Concurrent owner request conflict'); }
            return $this->receipt($request);
        });
        $j->boundary('owner-commit'); return $result;
    }
    public function lookup(Request $request): LookupResult
    {
        $this->j->current($request->context,true); $db=$this->j->observer();
        $row=$db->row('SELECT request_hash FROM '.$db->table('example_owner_writes').' WHERE tenant_id=:tenant AND operation_key=:key',
            ['tenant'=>$request->action->tenant->value,'key'=>$request->operationKey]);
        if ($row!==null && Connection::text($row,'request_hash')!==$request->requestHash->value) { throw new IntegrityConflict('Owner lookup binding conflict'); }
        return $row===null?new LookupResult(LookupStatus::Unknown,$this->j->ref($request->operationKey),$this->namespace(),$request->requestHash):$this->receipt($request);
    }
    private function receipt(Request $request): LookupResult
    {
        return new LookupResult(LookupStatus::Succeeded,$this->j->ref($request->operationKey),$this->namespace(),$request->requestHash,$this->j->ref($request->operationKey));
    }
}
