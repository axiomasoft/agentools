<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenToolsPlatform\Core\Actions\{ActionDefinition, ActionRegistration, ActionRegistry, EffectClass};
use AgenToolsPlatform\Core\Common\{AcceptedTurn, CanonicalHasher, IdentityKeys, InputPayload, InstanceId, PayloadHash, Ref, RunId, SessionId, SourceIdentity, TenantId, TurnId, UtcInstant};
use AgenToolsPlatform\Core\Context\{CompiledContext, ContextBlock, ContextTarget, Provenance, ReadContextCompiler, ToolAccess};
use AgenToolsPlatform\Core\Models\{Capability, CapabilityEvidence, ContentPart, Deployment, Evidence, GenerationOptions, JsonObject, MessageRole, ModelMessage, ModelRequest, Support, ToolDefinition};
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Runtime\{AdvanceLease, AdvanceRun, ContinuationPolicy, InvocationPlan};
use AgenToolsPlatform\Core\Security\{Correlation, ExecutionContext, TrustedInput};
use AgenToolsPlatform\Postgres\Claims\{ClaimPolicy, ClaimRepository};
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Models\InvocationRepository;
use AgenToolsPlatform\Postgres\Outbox\{OutboxRepository, Relay, WakeSignal};
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext, RunRepository, SessionBinding, SessionRepository};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

/** Runnable local host, with explicitly scripted external owners and real platform persistence. */
final class SupportJourney
{
    public readonly Connection $db;
    public readonly TenantId $tenant;
    public readonly SessionId $session;
    public readonly CanonicalHasher $hasher;
    public readonly Host $host;
    public readonly ExecutionContext $context;
    public readonly RepositoryContext $environment;
    public readonly ActionDefinition $readDefinition;
    public readonly ActionRegistry $registry;
    public readonly ReadOwner $owner;
    public readonly Policy $policy;
    public readonly Guard $guard;
    public readonly Vault $vault;
    public readonly Budget $budget;
    public readonly ScriptedModel $model;
    public readonly ReadActions $actions;
    public readonly Delivery $delivery;
    public readonly BundleRef $bundle;
    public readonly SourceIdentity $source;
    public readonly RunId $runId;
    public readonly TurnId $turnId;
    public ?\Closure $barrier = null;
    public bool $lookupSupported = true;
    public bool $loseDestinationAck = false;
    public ?AdvanceLease $workerLease = null;
    public ?InvocationPlan $activePlan = null;

    public function __construct(public IsolatedDatabase $database, public string $schema,
        string $tenant = 'support-tenant', public string $sourceNamespace = 'support')
    {
        $database->verify(); $this->db=new Connection($database->pdo,$schema);$this->tenant=new TenantId($tenant);
        $this->session=new SessionId($this->tenant,'headless:'.$sourceNamespace);
        $this->hasher=new CanonicalHasher(new Jcs());$this->host=new Host($this);
        $this->db->execute('INSERT INTO '.$this->db->table('demo_permissions').'(tenant_id) VALUES (:tenant) ON CONFLICT DO NOTHING',['tenant'=>$tenant]);
        $this->db->execute('INSERT INTO '.$this->db->table('demo_tickets').'(tenant_id,id,subject_ref,status,private_note) VALUES (:tenant,\'ST-17\',\'subject\',\'open\',\'PRIVATE-OWNER-NOTE\') ON CONFLICT DO NOTHING',['tenant'=>$tenant]);
        $this->context=ExecutionContext::fromHost($this->host,$this->session,'support.read',new Correlation('headless-trace','request-17'));
        $this->environment=new RepositoryContext($this->db,$this->hasher,$this->host,new Revisions($this));
        $this->readDefinition=new ActionDefinition('support.ticket.read','1','support.ticket',
            '{"type":"object","properties":{"ticket_ref":{"type":"string","const":"ST-17"}},"required":["ticket_ref"],"additionalProperties":false}',
            '{"type":"object","properties":{"status":{"type":"string"}},"required":["status"],"additionalProperties":false}',
            EffectClass::Read,['support.ticket.read'],false,1000,2,10,10000,'native_key',1,'local-test','host-policy','minimal-status',['ticket_ref'],null,new Jcs());
        $this->owner=new ReadOwner($this);$this->registry=new ActionRegistry([new ActionRegistration($this->readDefinition,$this->owner)]);
        $this->policy=new Policy($this);$this->guard=new Guard($this);$this->vault=new Vault($this);$this->budget=new Budget($this);
        $hash=$this->hasher->hash($this->tenant,'read-profile', ['definition'=>$this->readDefinition->ref->toArray(),'read_disclosure'=>'private-owner-acl','cost_bound_minor'=>1]);
        $this->bundle=new BundleRef($this->ref('profile:support-read:v1'),1,$hash);
        (new SessionRepository($this->environment))->bind($this->context,new SessionBinding($this->session,new InstanceId($this->tenant,'assistant'),
            $this->ref('definition:support:v1'),$hash,$hash,$this->bundle->ref,$hash,$this->ref('conversation:synthetic')));
        $this->source=new SourceIdentity(new InstanceId($this->tenant,'assistant'),$sourceNamespace,'request-17');
        $keys=new IdentityKeys($this->hasher);$this->turnId=$keys->turn($this->source);$this->runId=$keys->run($this->turnId);
        $this->model=new ScriptedModel($this);$this->actions=new ReadActions($this);$this->delivery=new Delivery($this);
    }

    public static function install(IsolatedDatabase $database, string $schema): void
    {
        $database->verify();$database->createSchema($schema);(new \AgenToolsPlatform\Postgres\Migrator($database->pdo,$schema))->migrate();
        $sql=file_get_contents(dirname(__DIR__,4).'/conformance/platform/read-journey/host.sql');
        if($sql===false){throw new \RuntimeException('Required host fixture missing');}
        $database->pdo->exec('SET search_path TO "'.$schema.'",pg_catalog');$database->pdo->exec($sql);
        $statement=$database->pdo->prepare('INSERT INTO demo_config(id,key_value) VALUES (\'vault-key\',:key)');
        $statement->execute(['key'=>base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))]);
    }
    public function ref(string $value): Ref { return new Ref($this->tenant,$value); }
    public function idle(): void { $this->db->assertIdle(); }
    public function boundary(string $name): void { $this->idle();if($this->barrier!==null){($this->barrier)($name);} }
    public function vaultKey(): string
    {
        $row=$this->db->row('SELECT key_value FROM '.$this->db->table('demo_config').' WHERE id=\'vault-key\'');
        $key=$row===null?false:base64_decode(Connection::text($row,'key_value'),true);
        if($key===false || strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES){throw new \RuntimeException('Host vault key unavailable');}return $key;
    }
    public function count(string $table): int
    {
        $row=$this->db->row('SELECT count(*) AS total FROM '.$this->db->table($table));
        return $row===null?throw new \RuntimeException('Missing count'):Connection::number($row,'total');
    }
    public function deployment(): Deployment
    {
        $caps=[];foreach([Capability::Text,Capability::Tools] as $cap){$caps[$cap->value]=new CapabilityEvidence(Support::Supported,Evidence::ProbeVerified,
            new UtcInstant('2026-10-31T00:00:00Z'),new UtcInstant('2031-01-01T00:00:00Z'),'scripted-local-host/v1');}
        return new Deployment($this->ref('scripted-support/v1'),'scripted','synthetic-account','local','scripted-support-1',$caps);
    }
    public function compiled(bool $answer=false): CompiledContext
    {
        $expiry=new UtcInstant('2030-01-01T00:00:00Z');$blocks=[];
        $content=[['policy','policy','Disclose only owner-authorized minimal ticket status.'],['profile','policy','Read-only support assistant; no write tools.'],
            ['tools','policy',json_encode($this->registry->modelView($this->policy->current($this->context)),JSON_THROW_ON_ERROR)],
            ['input','untrusted','What is the status of ticket ST-17?']];
        if($answer){$result=$this->owner->saved();$content[]=['evidence','owner',$result['model_view']];}
        foreach($content as $index=>[$kind,$trust,$text]){
            $source=$this->ref($kind==='input'?'input:request-17':'source:'.$kind);$evidence=[];
            if($kind==='evidence'){
                $read=$this->db->row('SELECT payload_ref FROM '.$this->db->table('demo_reads').' WHERE tenant_id=:tenant',['tenant'=>$this->tenant->value]);
                $source=$this->ref(Connection::text($read??throw new \RuntimeException('Missing result ref'),'payload_ref'));$evidence=[$this->ref('ticket-revision:1')];
            }
            $provenance=$kind==='tools'?new Provenance(toolId:$this->readDefinition->ref->id):new Provenance();
            $blocks[]=new ContextBlock($this->ref('block:'.$kind),$kind,$source,'1',$this->ref('host-owner'),
                $trust,'private','support.read',$source,$text,$expiry,$kind!=='tools',100-$index,$this->hasher,$evidence,$provenance);
        }
        $tools=new class($this) implements ToolAccess {
            public function __construct(private SupportJourney $journey) {}
            public function permits(ExecutionContext $context, string $toolId): bool
            {
                return $toolId===$this->journey->readDefinition->ref->id
                    && $this->journey->policy->current($context)->allows($this->journey->readDefinition);
            }
        };
        $compiler=new ReadContextCompiler($this->policy,$this->hasher,tools:$tools);
        $plan=$compiler->buildPlan($this->context,$this->turnId,$this->runId,$this->bundle,$blocks,$this->guard->now());
        $compiled=$compiler->finalize($plan,new ContextTarget($this->deployment()->ref,8192,256,128,256),new Counter($this->deployment()->ref),$this->guard->now());
        $this->db->execute('INSERT INTO '.$this->db->table('demo_snapshots').'(tenant_id,id,revision,manifest) VALUES (:tenant,:id,1,CAST(:manifest AS jsonb)) ON CONFLICT DO NOTHING',
            ['tenant'=>$this->tenant->value,'id'=>$compiled->snapshot->ref->value,'manifest'=>$compiled->manifestJson]);
        return $compiled;
    }
    public function accept(): AcceptedTurn
    {
        $compiled=$this->compiled();$json=(new Jcs())->canonicalize(['ticket_ref'=>'ST-17','text'=>'What is the status of ticket ST-17?']);
        $hash=new PayloadHash('sha-256:'.hash('sha256',$json));$ref=$this->ref('input:request-17');$this->vault->put($this->context,$ref,$hash,$json);
        $input=new TrustedInput($this->context,$this->source,new InputPayload($ref,$hash,$this->ref('conversation:synthetic'),$this->ref('message:request-17')));
        $accepted=(new IntakeRepository($this->environment))->accept($input,$compiled->snapshot);
        $accepted->runId->assertSame($this->runId);$accepted->turnId->assertSame($this->turnId);$this->boundary('intake');$this->signal();return $accepted;
    }
    private function signal(): void
    {
        (new Relay($this->environment,new OutboxRepository($this->environment)))->once($this->context,function(WakeSignal $signal):void{
            $this->idle();$signal->run->assertSame($this->runId);
            $observer=new Connection((new IsolatedDatabase())->pdo,$this->schema);
            $row=$observer->row('SELECT count(*) AS total FROM '.$observer->table('runs').' r JOIN '.$observer->table('outbox').' o ON o.tenant_id=r.tenant_id AND o.aggregate_ref=r.id
                WHERE r.tenant_id=:tenant AND r.id=:run AND o.event_key=:event', ['tenant'=>$this->tenant->value,'run'=>$this->runId->value,'event'=>$signal->eventKey]);
            if($row===null || Connection::number($row,'total')!==1){throw new \RuntimeException('Signal before committed intake/outbox');}
            $this->db->execute('INSERT INTO '.$this->db->table('demo_signals').'(tenant_id,event_key,payload_hash,run_id) VALUES (:tenant,:event,:hash,:run) ON CONFLICT DO NOTHING',
                ['tenant'=>$this->tenant->value,'event'=>$signal->eventKey,'hash'=>$signal->payloadHash,'run'=>$this->runId->value]);
            $saved=$this->db->row('SELECT payload_hash FROM '.$this->db->table('demo_signals').' WHERE tenant_id=:tenant AND event_key=:event', ['tenant'=>$this->tenant->value,'event'=>$signal->eventKey]);
            if($saved===null || Connection::text($saved,'payload_hash')!==$signal->payloadHash){throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Wake payload conflict');}
            $this->boundary('signal');
        });
    }
    public function plan(bool $answer=false): InvocationPlan
    {
        $compiled=$this->compiled($answer);$tools=[];$pins=[];
        if(!$answer){foreach($this->registry->modelView($this->policy->current($this->context)) as $view){
            $tools[]=new ToolDefinition('support_read','Read minimal owner-authorized ticket status',new JsonObject(['type'=>'object','properties'=>['ticket_ref'=>['type'=>'string','const'=>'ST-17']],'required'=>['ticket_ref'],'additionalProperties'=>false]));
            $pins['support_read']=$this->readDefinition->ref;
        }}
        $step=$answer?'final-answer':'read-proposal';$keys=new IdentityKeys($this->hasher);$id=$keys->invocation($this->runId,$step,1);
        $request=new ModelRequest($id,[new ModelMessage(MessageRole::User,[ContentPart::text($compiled->rendered)])],$compiled->snapshot->ref,$compiled->snapshot->contentHash,
            $this->ref('credential:scripted'),new UtcInstant('2030-01-01T00:00:00Z'),$this->guard,tools:$tools,generation:new GenerationOptions(maxOutputTokens:256));
        $plan=new InvocationPlan($this->runId,$this->turnId,$step,$request,$this->ref('request:'.$id->value),$this->deployment(),$compiled->snapshot,$this->bundle,
            $this->ref('business:support-17'),$this->ref($this->sourceNamespace==='batch'?'batch:result-17':'support:reply-17'),$pins,new ContinuationPolicy(4,1,2,1,4096),(new Counter($this->deployment()->ref))->count($compiled->rendered)+256,
            $answer?$keys->invocation($this->runId,'read-proposal',1)->value:null,$answer?'authorized-read-result':null,$this->hasher);
        $json=json_encode(['messages'=>$request->messages,'tools'=>$request->tools,'snapshot'=>$compiled->snapshot->toArray()],JSON_THROW_ON_ERROR);
        $this->vault->put($this->context,$plan->requestRef,new PayloadHash('sha-256:'.hash('sha256',$json)),$json);return $plan;
    }
    public function driveOnce(): string
    {
        $run=(new RunRepository($this->environment))->get($this->context,$this->runId)??throw new \RuntimeException('Accept input first');
        if($run->status->terminal()){return $run->status->value;}
        $row=$this->db->row('SELECT state_ref FROM '.$this->db->table('run_steps').' WHERE tenant_id=:tenant AND run_id=:run AND step_key=\'read-proposal\'',
            ['tenant'=>$this->tenant->value,'run'=>$this->runId->value]);
        $state=$row===null?null:json_decode(Connection::text($row,'state_ref'),true,64,JSON_THROW_ON_ERROR);
        $answer=is_array($state) && ($state['stage']??null)==='continued';$plan=$this->plan($answer);$this->activePlan=$plan;
        if($run->status->value==='accepted'){$run=(new RunRepository($this->environment))->beginPreparation($this->context,$plan->snapshot,$this->runId,$run->version);}
        $claim=(new ClaimRepository($this->environment,new ClaimPolicy(300000)))->claim($this->context,$plan->snapshot,$this->runId,$run->version);
        if($claim===null){return 'busy';}
        $lease=new AdvanceLease($this->runId,$claim->state->version,$claim->state->fencingEpoch,$claim->owner);$this->workerLease=$lease;
        if(is_array($state) && ($state['stage']??null)==='waiting_action'){$this->actions->performRead();}
        $runtime=new AdvanceRun(new PreflightJournal($this,new InvocationRepository($this->environment)),$this->model,$this->budget,$this->guard,$this->vault,$this->actions,$this->hasher,$this->lookupSupported?$this->model:null);
        $saved=$runtime->advance($this->context,$lease,$plan);$this->workerLease=$saved->lease;
        if($saved->stage()==='continued'){
            // One job has finished its bounded step; release the claim without changing identity/state.
            $this->db->execute('UPDATE '.$this->db->table('runs').' SET version=version+1,claim_owner=NULL,lease_until=NULL WHERE tenant_id=:tenant AND id=:id AND claim_owner=:owner AND fencing_epoch=:epoch',
                ['tenant'=>$this->tenant->value,'id'=>$this->runId->value,'owner'=>$saved->lease->owner,'epoch'=>$saved->lease->epoch]);
        }
        if($saved->stage()==='complete'){$this->boundary('response');}return $saved->stage();
    }
    public function drive(): void
    {
        for($i=0;$i<6;++$i){$status=$this->driveOnce();if(in_array($status,['complete','completed'],true)){return;}
            if(in_array($status,['unknown','bounded','busy'],true)){throw new \RuntimeException('Journey requires explicit recovery: '.$status);}}
        throw new \RuntimeException('Journey step bound exhausted');
    }
    public function expireWorker(): void
    {
        $this->db->execute('UPDATE '.$this->db->table('runs').' SET version=version+1,lease_until=clock_timestamp()-interval \'1 microsecond\' WHERE tenant_id=:tenant AND id=:id AND claim_owner IS NOT NULL',
            ['tenant'=>$this->tenant->value,'id'=>$this->runId->value]);
        $this->db->execute('UPDATE '.$this->db->table('outbox').' SET version=version+1,claimed_until=clock_timestamp()-interval \'1 microsecond\' WHERE tenant_id=:tenant AND event_type=\'run.accepted\' AND claim_owner IS NOT NULL',['tenant'=>$this->tenant->value]);
    }
}
