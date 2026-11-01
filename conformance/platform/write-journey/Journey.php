<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Conformance\WriteJourney;

require_once __DIR__.'/Ports.php';

use AgenToolsPlatform\Core\Actions\{ActionApplication, ActionDefinition, ActionRequest, ActionSubmission, EffectClass, InputSchemaValidator};
use AgenToolsPlatform\Core\Actions\Confirmation\{ConfirmedAction, Decision, Preview};
use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, IdentityKeys, InputPayload, InstanceId, IntegrityConflict, PayloadHash, Ref, RunId, SessionId, SourceIdentity, TenantId, TurnId, UtcInstant};
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Models\{Capability, CapabilityEvidence, ContentPart, Deployment, Evidence, FinishReason, GenerationOptions, JsonObject, MessageRole, ModelMessage, ModelRequest, ModelResponse, ModelUsage, Support, ToolDefinition, ToolProposal};
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Runtime\{AdvanceLease, AdvanceRun, ContinuationPolicy, ExchangeResult, InvocationCheckpoint, InvocationPlan, SavedProposal};
use AgenToolsPlatform\Core\Security\{ExecutionContext, TrustedInput};
use AgenToolsPlatform\Examples\ConfirmedWrite\{Composition, Host, KeyMaterial};
use AgenToolsPlatform\Examples\DirectAction\Jcs;
use AgenToolsPlatform\Postgres\Actions\SubmissionLifecycle;
use AgenToolsPlatform\Postgres\Claims\{ClaimPolicy, ClaimRepository};
use AgenToolsPlatform\Postgres\Confirmation\{Journal, ScopedReplayStore, Service as Confirmation};
use AgenToolsPlatform\Postgres\Delivery\{DeliveryRepository, DeliverySubmission};
use AgenToolsPlatform\Postgres\Effects\{Ledger, Service as Effects};
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Models\InvocationRepository;
use AgenToolsPlatform\Postgres\Outbox\{OutboxRepository, Relay, WakeSignal};
use AgenToolsPlatform\Postgres\Runtime\{Connection, ContextRevision, RepositoryContext, RunRepository, SessionBinding, SessionRepository};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

/** Isolated host rehearsal. Existing Runtime owns proposals; existing Actions alone execute effects. */
final class Journey
{
    public const INPUT='{"recipient":"r1","document":"d1","amount":100}';
    public readonly Composition $composition;
    public readonly Connection $db;
    public readonly TenantId $tenant;
    public readonly CanonicalHasher $hasher;
    public readonly ExecutionContext $context;
    public readonly RepositoryContext $environment;
    public readonly SourceIdentity $source;
    public readonly RunId $run;
    public readonly TurnId $turn;
    public readonly Policy $policy;
    public readonly ActionApplication $actions;
    public readonly Confirmation $confirmation;
    public readonly Vault $vault;
    public readonly Budget $budget;
    public readonly Provider $provider;
    public ?InvocationPlan $activePlan=null;
    public ?\Closure $barrier=null;

    public function __construct(public IsolatedDatabase $database,public string $schema,string $tenant='tenant-a',public string $instance='assistant')
    {
        $database->verify(); $this->tenant=new TenantId($tenant);
        $definition=new ActionDefinition('document.write','1','documents.owner',Composition::INPUT,'{"type":"object"}',EffectClass::Write,
            ['documents.write'],true,1000,3,1000,10000,'native_key',null,'local-test','host-policy','minimal-receipt',['actor','outcome'],null,new Jcs());
        $keys=new KeyMaterial(str_repeat('s',32),str_repeat('e',32));
        $this->composition=new Composition($database->pdo,(new IsolatedDatabase())->pdo,$schema,new Host($this->tenant),$keys,$definition);
        $this->db=$this->composition->environment->db; $this->hasher=$this->composition->hasher;
        $this->db->execute('INSERT INTO '.$this->db->table('write_controls').' (tenant_id,instance_id) VALUES (:tenant,:instance) ON CONFLICT DO NOTHING',['tenant'=>$tenant,'instance'=>$instance]);
        $this->db->execute('INSERT INTO '.$this->db->table('example_owner_permissions').' VALUES (:tenant,\'actor\',true) ON CONFLICT DO NOTHING',['tenant'=>$tenant]);
        $this->context=$this->composition->context('write:'.$instance);
        $revisions=new class implements ContextRevision { public function current(ExecutionContext $context,Ref $snapshot): int { return 1; } };
        $this->environment=new RepositoryContext($this->db,$this->hasher,$this->composition->host,$revisions);
        $this->policy=new Policy($this);
        $validator=new class implements InputSchemaValidator {
            public function assertValid(string $schemaJson,string $inputJson): void {
                $value=json_decode($inputJson,true,512,JSON_THROW_ON_ERROR);
                if (!is_array($value) || count($value)!==3 || !is_string($value['recipient']??null) || !is_string($value['document']??null) || !is_int($value['amount']??null)) { throw new ContractViolation(ErrorCode::InvalidInput); }
            }
        };
        $this->actions=new ActionApplication($this->composition->registry,$this->policy,$this->composition->host,$validator,
            new SubmissionLifecycle($this->environment,$this->composition->registry,$this->policy,$validator));
        $this->confirmation=new Confirmation($this->environment,$this->actions,$this->composition->registry,$this->policy,$this->composition->host,$keys,
            new ScopedReplayStore((new IsolatedDatabase())->pdo,$schema),$keys,$this->composition->host);
        $hash=$this->hasher->hash($this->tenant,'write-profile',$definition->ref->toArray());
        (new SessionRepository($this->environment))->bind($this->context,new SessionBinding($this->context->session,new InstanceId($this->tenant,$instance),
            $this->ref('code:write:v1'),$hash,$hash,$this->ref('bundle:write:v1'),$hash,$this->ref('history:synthetic')));
        $this->source=new SourceIdentity(new InstanceId($this->tenant,$instance),'host.message','source-message-1');
        $ids=new IdentityKeys($this->hasher); $this->turn=$ids->turn($this->source); $this->run=$ids->run($this->turn);
        $this->vault=new Vault($this); $this->budget=new Budget($this); $this->provider=new Provider($this);
    }

    public static function install(IsolatedDatabase $database,string $schema,?string $migrations=null): void
    {
        $database->createSchema($schema);
        if ($migrations===null) { (new \AgenToolsPlatform\Postgres\Migrator($database->pdo,$schema))->migrate(); }
        else { (new \AgenToolsPlatform\Postgres\Migrator($database->pdo,$schema))->migrate($migrations); }
        $sql=file_get_contents(__DIR__.'/host.sql'); if ($sql===false) { throw new \RuntimeException('Missing host schema'); }
        $database->pdo->exec('SET search_path TO "'.$schema.'",pg_catalog'); $database->pdo->exec($sql);
        $database->pdo->prepare('INSERT INTO write_config VALUES (\'vault\',:key)')->execute(['key'=>base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))]);
    }
    public function ref(string $value): Ref { return new Ref($this->tenant,$value); }
    public function idle(): void { $this->db->assertIdle(); }
    public function observer(): Connection { return new Connection((new IsolatedDatabase())->pdo,$this->schema); }
    public function vaultKey(): string
    {
        $row=$this->db->row('SELECT value FROM '.$this->db->table('write_config').' WHERE id=\'vault\'');
        $key=$row===null?false:base64_decode(Connection::text($row,'value'),true);
        if ($key===false || strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) { throw new IntegrityConflict('Missing host vault key'); } return $key;
    }
    public function current(ExecutionContext $context,bool $owner=false): void
    {
        $this->idle(); $context->assertCurrent($this->composition->host); $this->tenant->assertSame($context->identity->tenant);
        $row=$this->db->row('SELECT tool_allowed,emergency FROM '.$this->db->table('write_controls').' WHERE tenant_id=:tenant AND instance_id=:instance',
            ['tenant'=>$this->tenant->value,'instance'=>$this->instance]);
        if ($row===null || $row['tool_allowed']!==true || $row['emergency']!==false) { throw new ContractViolation(ErrorCode::DeploymentUnavailable); }
        if ($owner) {
            $permission=$this->db->row('SELECT allowed FROM '.$this->db->table('example_owner_permissions').' WHERE tenant_id=:tenant AND actor_ref=:actor',
                ['tenant'=>$this->tenant->value,'actor'=>$context->identity->actorRef->value]);
            if ($permission===null || $permission['allowed']!==true) { throw new ContractViolation(ErrorCode::AccessDenied); }
        }
    }
    public function boundary(string $name): void { $this->idle(); if ($this->barrier!==null) { ($this->barrier)($name); } }
    public function snapshot(string $step='proposal'): ContextSnapshot
    {
        return new ContextSnapshot($this->ref('snapshot:'.$this->instance.':'.$step),1,
            $this->hasher->hash($this->tenant,'write-context',[$step,$this->composition->definition->ref->toArray()]),$this->context->scopeHash($this->hasher));
    }
    public function accept(string $input=self::INPUT): void
    {
        $hash=new PayloadHash('sha-256:'.hash('sha256',$input)); $ref=$this->ref('source-input:'.$this->instance);
        $this->vault->put($this->context,$ref,$hash,$input);
        (new IntakeRepository($this->environment))->accept(new TrustedInput($this->context,$this->source,
            new InputPayload($ref,$hash,$this->ref('conversation:synthetic'),$this->ref('message:source-message-1'))),$this->snapshot());
        $this->boundary('intake'); $this->signal();
    }
    private function signal(): void
    {
        $row=$this->db->row('SELECT status FROM '.$this->db->table('outbox').' WHERE tenant_id=:tenant AND event_key=:event',
            ['tenant'=>$this->tenant->value,'event'=>'run.accepted:'.$this->run->value]);
        if (($row['status']??null)==='dispatched') { return; }
        (new Relay($this->environment,new OutboxRepository($this->environment)))->once($this->context,function (WakeSignal $signal): void {
            $this->idle(); $signal->run->assertSame($this->run);
            $this->db->execute('INSERT INTO '.$this->db->table('write_signals').' VALUES (:tenant,:id,:hash) ON CONFLICT DO NOTHING',
                ['tenant'=>$this->tenant->value,'id'=>$signal->eventKey,'hash'=>$signal->payloadHash]);
            $saved=$this->db->row('SELECT hash FROM '.$this->db->table('write_signals').' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$this->tenant->value,'id'=>$signal->eventKey]);
            if ($saved===null || Connection::text($saved,'hash')!==$signal->payloadHash) { throw new IntegrityConflict('Wake receiver hash conflict'); }
            $this->boundary('signal-commit');
        });
        $this->boundary('wake');
    }
    public function plan(bool $response=false): InvocationPlan
    {
        $step=$response?'response':'proposal'; $snapshot=$this->snapshot($step); $ids=new IdentityKeys($this->hasher);
        $caps=[]; foreach ([Capability::Text,Capability::Tools] as $cap) { $caps[$cap->value]=new CapabilityEvidence(Support::Supported,Evidence::ProbeVerified,
            new UtcInstant('2026-10-31T00:00:00Z'),new UtcInstant('2031-01-01T00:00:00Z'),'synthetic-write-v1'); }
        $deployment=new Deployment($this->ref('synthetic-write:v1'),'scripted','synthetic-account','local','synthetic-model',$caps);
        $tools=$response?[]:[new ToolDefinition('document_write','Propose a confirmed document write',new JsonObject(['type'=>'object']))];
        $request=new ModelRequest($ids->invocation($this->run,$step,1),[new ModelMessage(MessageRole::User,[ContentPart::text($response?'Report the verified owner receipt.':'Propose the selected write.')])],
            $snapshot->ref,$snapshot->contentHash,$this->ref('credential:synthetic'),new UtcInstant('2030-01-01T00:00:00Z'),tools:$tools,generation:new GenerationOptions(maxOutputTokens:128));
        $bundle=new BundleRef($this->ref('bundle:write:v1'),1,$this->hasher->hash($this->tenant,'write-bundle',$this->composition->definition->ref->toArray()));
        return new InvocationPlan($this->run,$this->turn,$step,$request,$this->ref('request:'.$request->invocationId->value),$deployment,$snapshot,$bundle,
            $this->ref('business:source-message-1'),$this->ref('destination:'.$this->instance),$response?[]:['document_write'=>$this->composition->definition->ref],
            new ContinuationPolicy(4,1,2,1,4096),32,$response?$ids->invocation($this->run,'proposal',1)->value:null,$response?'owner-confirmed':null,$this->hasher);
    }
    public function response(InvocationPlan $plan,string $body): ModelResponse
    {
        $data=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['arguments']??null) || !is_bool($data['response']??null)) { throw new IntegrityConflict('Malformed cached provider response'); }
        $args=[]; foreach ($data['arguments'] as $key=>$value) { if (!is_string($key)) { throw new IntegrityConflict('Malformed provider arguments'); } $args[$key]=$value; }
        return new ModelResponse($plan->request->invocationId,$plan->deployment,$plan->deployment,'provider:'.$plan->request->invocationId->value,
            $data['response']?FinishReason::Completed:FinishReason::ToolProposal,[ContentPart::text($data['response']?'Write complete.':'Write proposed.')],
            $data['response']?[]:[new ToolProposal($plan->request->invocationId,'proposal-1','document_write',new JsonObject($args))],usage:new ModelUsage(32,8,0,null));
    }
    public function claim(int $leaseMs=300000): AdvanceLease
    {
        $run=(new RunRepository($this->environment))->get($this->context,$this->run)??throw new \LogicException('No run');
        $claim=(new ClaimRepository($this->environment,new ClaimPolicy($leaseMs)))->claim($this->context,$this->snapshot(),$this->run,$run->version)
            ??throw new ContractViolation(ErrorCode::StaleClaim);
        return new AdvanceLease($this->run,$claim->state->version,$claim->state->fencingEpoch,$claim->owner);
    }
    public function advance(bool $response=false): InvocationCheckpoint
    {
        $plan=$this->plan($response); $this->activePlan=$plan;
        $run=(new RunRepository($this->environment))->get($this->context,$this->run)??throw new \LogicException('No accepted source');
        if ($run->status->value==='accepted') { (new RunRepository($this->environment))->beginPreparation($this->context,$plan->snapshot,$this->run,$run->version); }
        $runtime=new AdvanceRun(new BoundaryJournal($this,new InvocationRepository($this->environment)),$this->provider,$this->budget,$this->policy,$this->vault,new Dispatcher($this),$this->hasher,$this->provider);
        $saved=$runtime->advance($this->context,$this->claim(),$plan);
        if ($saved->stage()==='continued') {
            $this->db->execute('UPDATE '.$this->db->table('runs').' SET version=version+1,claim_owner=NULL,lease_until=NULL
                WHERE tenant_id=:tenant AND id=:id AND version=:version AND fencing_epoch=:epoch AND claim_owner=:owner',
                ['tenant'=>$this->tenant->value,'id'=>$this->run->value,'version'=>$saved->lease->version,'epoch'=>$saved->lease->epoch,'owner'=>$saved->lease->owner]);
        }
        return $saved;
    }
    public function proposal(): SavedProposal
    {
        $plan=$this->plan(); $row=$this->db->row('SELECT proposal_ref FROM '.$this->db->table('proposal_map').' WHERE tenant_id=:tenant AND run_id=:run',
            ['tenant'=>$this->tenant->value,'run'=>$this->run->value])??throw new IntegrityConflict('Missing proposal mapping');
        $result=ExchangeResult::decode($plan,$this->vault->get($this->context,$this->ref(Connection::text($row,'proposal_ref'))),$this->hasher);
        return $result->proposals[0]??throw new IntegrityConflict('Missing normalized proposal');
    }
    public function submission(?SavedProposal $proposal=null): ActionSubmission
    {
        $proposal??=$this->proposal();
        return new ActionSubmission(new ActionRequest($proposal->definition,$this->ref('origin:'.$this->run->value),$proposal->id,
            $this->ref($proposal->id->value),$this->ref('action-input:'.$proposal->id->value),$proposal->inputHash,$this->context,$proposal->deadline,$this->hasher),
            $proposal->argumentsJson,$this->hasher,new Jcs());
    }
    public function confirm(): ConfirmedAction
    {
        $submission=$this->submission(); $preview=$this->confirmation->prepare($submission); $id=$preview->binding->action;
        $row=$this->db->row('SELECT seq,epoch FROM '.$this->db->table('agentools_events').' WHERE tenant_id=:tenant AND action_id=:action AND kind=\'confirmation_resolved\' AND payload_json::jsonb->>\'decision\'=\'approve\'',
            ['tenant'=>$this->tenant->value,'action'=>$id->value]);
        if ($row!==null) { return new ConfirmedAction($id,$preview->proposal,$preview->binding->hash,Connection::number($row,'seq'),$preview->binding->epoch); }
        $this->composition->host->consent=new Decision($this->ref('ui:'.$preview->proposal->value),$this->hasher->hash($this->tenant,'ui-proof',$preview->binding->hash->value),
            $this->context->identity->actorRef,$preview->binding->hash,'ui',$preview->binding->expiresAt);
        $this->confirmation->approve($preview->token,$submission); $this->boundary('proof');
        $grant=$this->confirmation->commit($preview->token,$submission); $this->boundary('confirmation'); return $grant;
    }
    public function ledger(ConfirmedAction $grant,?int $epoch=null): Ledger { return new Ledger($this->environment,$grant,$this->submission(),$this->composition->registry,$this->policy,$epoch); }
    public function write(): void
    {
        $this->current($this->context); $grant=$this->confirm();
        $outcome=$this->actions->status($grant->action,$this->context)->outcome()?->value;
        if ($outcome==='succeeded') { return; }
        if ($outcome==='failed') { throw new ContractViolation(ErrorCode::AccessDenied); }
        $ledger=$this->ledger($grant); $service=new Effects($ledger,new Owner($this,$ledger->epoch));
        $existing=$this->db->row('SELECT retry_after<=clock_timestamp() AS ready FROM '.$this->db->table('effect_intents').' WHERE tenant_id=:tenant AND action_ref=:action',
            ['tenant'=>$this->tenant->value,'action'=>$grant->action->value]);
        if ($existing===null) { $service->execute(); }
        else {
            try { $service->reconcile(); }
            catch (\AgenTools\Effects\EffectReconciliationRequired) {
                if ($existing['ready']!==true) { $this->db->execute('SELECT pg_sleep(1.01)'); }
                $service->reconcile(true);
            }
        }
        if ($ledger->accepted()?->status===\AgenToolsPlatform\Core\Actions\Effects\LookupStatus::Failed) { throw new ContractViolation(ErrorCode::AccessDenied); }
        $this->boundary('effect-confirmed');
    }
    public function drive(): string
    {
        $this->accept();
        for ($i=0;$i<6;++$i) {
            $run=(new RunRepository($this->environment))->get($this->context,$this->run)??throw new \LogicException('No run');
            if ($run->status->terminal()) { return $run->status->value; }
            $row=$this->db->row('SELECT state_ref FROM '.$this->db->table('run_steps').' WHERE tenant_id=:tenant AND run_id=:run AND step_key=\'proposal\'',
                ['tenant'=>$this->tenant->value,'run'=>$this->run->value]);
            $data=$row===null?null:json_decode(Connection::text($row,'state_ref'),true,512,JSON_THROW_ON_ERROR);
            $stage=is_array($data)?($data['stage']??null):null;
            if ($stage==='waiting_action') { $this->write(); }
            $saved=$this->advance($stage==='continued');
            if ($saved->stage()==='unknown') { return 'unresolved'; }
        }
        throw new \LogicException('Write journey bound exhausted');
    }
    public function deliver(): void
    {
        $this->current($this->context,true);
        $row=$this->db->row('SELECT d.*,r.payload_ref_id FROM '.$this->db->table('delivery_intents').' d JOIN '.$this->db->table('responses').' r
            ON r.tenant_id=d.tenant_id AND r.id=d.response_id WHERE d.tenant_id=:tenant AND d.run_id=:run',
            ['tenant'=>$this->tenant->value,'run'=>$this->run->value])??throw new IntegrityConflict('No committed response/delivery');
        $repo=new DeliveryRepository($this->environment,function (): void { $this->current($this->context,true); });
        $source=$this->ref('output:'.$this->instance); $repo->openSource($this->context,$source,1);
        $proposal=new DeliverySubmission(Connection::text($row,'delivery_key'),$this->run,$this->ref(Connection::text($row,'response_id')),1,
            $this->ref(Connection::text($row,'payload_ref_id')),new PayloadHash('sha-256:'.Connection::text($row,'payload_hash')),
            $this->ref(Connection::text($row,'destination')),$source,1,$this->context->identity->policyRevision,new PayloadHash('sha-256:'.Connection::text($row,'payload_hash')));
        $repo->submit($this->context,$proposal);
        if ($repo->status($this->context,$proposal->key)['state']==='delivered') { return; }
        $claim=$repo->claim($this->context,$proposal->key)??throw new ContractViolation(ErrorCode::StaleClaim); $this->boundary('delivery-claim');
        $repo->assertCurrent($this->context,$proposal,$claim->version.':'.$claim->epoch.':'.$claim->owner);
        $owner=$this->observer();
        $receipt=$owner->transaction(function () use ($owner,$proposal): Ref {
            $permission=$owner->row('SELECT allowed FROM '.$owner->table('example_owner_permissions').' WHERE tenant_id=:tenant AND actor_ref=\'actor\' FOR SHARE',['tenant'=>$this->tenant->value]);
            if ($permission===null || $permission['allowed']!==true) { throw new ContractViolation(ErrorCode::AccessDenied); }
            $receipt=$this->ref('destination-receipt:'.$proposal->key);
            $owner->execute('INSERT INTO '.$owner->table('write_messages').' VALUES (:tenant,:id,:hash,:receipt) ON CONFLICT DO NOTHING',
                ['tenant'=>$this->tenant->value,'id'=>$proposal->key,'hash'=>$proposal->hash->value,'receipt'=>$receipt->value]);
            $saved=$owner->row('SELECT hash FROM '.$owner->table('write_messages').' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$this->tenant->value,'id'=>$proposal->key]);
            if ($saved===null || Connection::text($saved,'hash')!==$proposal->hash->value) { throw new IntegrityConflict('Destination hash conflict'); }
            return $receipt;
        });
        $this->boundary('destination-commit'); $repo->settle($this->context,$claim,'success',$receipt); $this->boundary('delivery-receipt');
    }
    public function expire(): void
    {
        foreach (['runs'=>['lease_until','claim_owner'],'outbox'=>['claimed_until','claim_owner'],'delivery_controls'=>['lease_until','claim_owner']] as $table=>[$lease,$owner]) {
            $this->db->execute('UPDATE '.$this->db->table($table).' SET version=version+1,'.$lease.'=clock_timestamp()-interval \'1 microsecond\'
                WHERE tenant_id=:tenant AND '.$owner.' IS NOT NULL',['tenant'=>$this->tenant->value]);
        }
    }
    public function count(string $table): int { return (int)$this->db->execute('SELECT count(*) FROM '.$this->db->table($table).' WHERE tenant_id=:tenant',['tenant'=>$this->tenant->value])->fetchColumn(); }
}
