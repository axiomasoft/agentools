<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Voice;

use AgenToolsPlatform\Core\Actions\{ActionApplication, ActionDefinition, ActionHandler, ActionRegistration, ActionRegistry, EffectClass, InputSchemaValidator};
use AgenToolsPlatform\Core\Common\{AcceptedTurn, CanonicalHasher, ContractViolation, ErrorCode, IdentityKeys, InstanceId, PayloadHash, Ref, SessionId, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Context\{CompiledContext, ContextBlock, ContextPlan, ContextTarget, GuardSnapshot, Provenance, ReadContextCompiler, TokenCounter, ToolAccess};
use AgenToolsPlatform\Core\Models\{Capability, CapabilityEvidence, ContentPart, Deployment, Evidence, GenerationOptions, JsonObject, MessageRole, ModelMessage, ModelRequest, Support, ToolDefinition};
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Runtime\{AdvanceLease, AdvanceRun, ContinuationPolicy, InvocationCheckpoint, InvocationPlan};
use AgenToolsPlatform\Core\Security\{BoundaryGuard, DisclosureStream, EntryPoint, GeneratedChunk, TrustedInput};
use AgenToolsPlatform\Loqum\Ingress\{Boundary, Envelope};
use AgenToolsPlatform\Loqum\Delivery\{Coordinator, Ledger, Response, Schema, Segment, UiCommand};
use AgenToolsPlatform\Postgres\Actions\SubmissionLifecycle;
use AgenToolsPlatform\Postgres\Claims\{ClaimPolicy, ClaimRepository};
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Models\InvocationRepository;
use AgenToolsPlatform\Postgres\Runtime\{Connection as C, RepositoryContext, RunRepository, SessionBinding, SessionRepository};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

/** Runnable synthetic audio-event host. One real core/runtime/intake/compiler path for voice and text. */
final class Journey
{
    public C $db;
    public TenantId $tenant;
    public SessionId $session;
    public InstanceId $assistant;
    public CanonicalHasher $hasher;
    public Host $host;
    public Vault $vault;
    public Boundary $ingress;
    public RepositoryContext $environment;
    public ActionDefinition $definition;
    public ActionRegistry $registry;
    public ActionApplication $actions;
    public BundleRef $bundle;
    public Guard $guard;
    public Ledger $ledger;
    public Coordinator $delivery;
    public AcceptedTurn $accepted;
    public TrustedInput $input;
    public CompiledContext $compiled;
    public ContextPlan $contextPlan;
    public ContextTarget $target;
    public InvocationPlan $plan;
    public ?InvocationCheckpoint $checkpoint=null;
    public ?AdvanceLease $lease=null;
    public bool $propose=false;
    /** @var list<Segment> */ public array $segments=[];
    public ?Response $response=null;
    /** @var array<string,array{compiled:CompiledContext,plan:ContextPlan,target:ContextTarget}> */ private array $compiledInputs=[];

    public function __construct(IsolatedDatabase $database,string $schema,string $tenant='voice-a',?string $vaultKey=null)
    {
        $database->verify();$this->db=new C($database->pdo,$schema);$this->tenant=new TenantId($tenant);
        $this->session=new SessionId($this->tenant,'assistant-session');$this->assistant=new InstanceId($this->tenant,'voice-assistant');
        $this->hasher=new CanonicalHasher(new Json());$this->host=new Host($this);
        $this->db->execute('INSERT INTO '.$this->db->table('voice_demo_state').' (tenant_id) VALUES(:tenant) ON CONFLICT DO NOTHING',['tenant'=>$tenant]);
        $this->vault=new Vault($this,$vaultKey??random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));$this->ingress=new Boundary($this->host,$this->hasher);
        $this->environment=new RepositoryContext($this->db,$this->hasher,$this->host,$this->host);
        $this->definition=new ActionDefinition('voice.example.read','1','voice.example.owner','{"type":"object","required":["query"],"properties":{"query":{"type":"string","const":"fixture"}},"additionalProperties":false}',
            '{"type":"object"}',EffectClass::Read,['voice.example.read'],false,1000,1,0,3000,'native_key',null,'local','technical-ref-only','omit-payload',[],null,new Json());
        $handler=new class implements ActionHandler { public function handlerId(): string { return 'voice.example.owner'; } };
        $this->registry=new ActionRegistry([new ActionRegistration($this->definition,$handler)]);$policy=new Policy($this);
        $schemas=new class implements InputSchemaValidator {
            public function assertValid(string $schemaJson,string $inputJson): void {
                $data=json_decode($inputJson,true,512,JSON_THROW_ON_ERROR);
                if ($data!==['query'=>'fixture']) { throw new ContractViolation(ErrorCode::InvalidInput); }
            }
        };
        $this->actions=new ActionApplication($this->registry,$policy,$this->host,$schemas,new SubmissionLifecycle($this->environment,$this->registry,$policy,$schemas));
        $hash=$this->hasher->hash($this->tenant,'voice-bundle',[$this->definition->ref->toArray(),'one-core-compiler/v1']);
        $this->bundle=new BundleRef($this->ref('voice-bundle'),1,$hash);$context=\AgenToolsPlatform\Core\Security\ExecutionContext::fromHost($this->host,$this->session,'voice.assist',new \AgenToolsPlatform\Core\Security\Correlation('voice-trace','voice-request'));
        (new SessionRepository($this->environment))->bind($context,new SessionBinding($this->session,$this->assistant,$this->ref('voice-definition'),$hash,$hash,$this->bundle->ref,$hash,$this->ref('conversation:voice')));
        $this->guard=new Guard($this);$this->ledger=new Ledger($this->db,$this->hasher);$this->delivery=new Coordinator($this->ledger,$this->host,$this->hasher,$this->host);
    }
    public static function install(IsolatedDatabase $database,string $schema): void
    {
        $database->verify();$database->createSchema($schema);(new \AgenToolsPlatform\Postgres\Migrator($database->pdo,$schema))->migrate();
        $sql=file_get_contents(dirname(__DIR__).'/schema.sql');if ($sql===false) { throw new \RuntimeException('Voice owner fixture missing'); }
        $database->pdo->exec('SET search_path TO "'.$schema.'",pg_catalog');$database->pdo->exec($sql);Schema::install(new C($database->pdo,$schema));
    }
    public function ref(string $value): Ref { return new Ref($this->tenant,$value); }
    public function count(string $table): int
    {
        $r=$this->db->row('SELECT count(*) AS total FROM '.$this->db->table($table).' WHERE tenant_id=:tenant',['tenant'=>$this->tenant->value]);return C::number($r??throw new \RuntimeException('Missing table'),'total');
    }
    public function modelCalls(): int
    {
        $r=$this->db->row('SELECT coalesce(sum(attempts),0) AS total FROM '.$this->db->table('voice_demo_provider').' WHERE tenant_id=:tenant',['tenant'=>$this->tenant->value]);
        return C::number($r??throw new \RuntimeException('Missing model counter'),'total');
    }
    /**
     * Actual synthetic runtime tuple; a live/domain certificate is never inferred from it.
     * @return array<string,string>
     */
    public function tuple(): array
    {
        $corpus=hash_file('sha256',dirname(__DIR__).'/events.json');
        if ($corpus===false) { throw new \RuntimeException('Required synthetic corpus missing'); }
        return ['deployment_ref'=>$this->plan->deployment->ref->value,'model_revision'=>$this->plan->deployment->model,
            'profile_ref'=>$this->bundle->ref->value,'profile_bundle_hash'=>$this->bundle->hash->value,'task_ref'=>'voice-text-synthetic/v1',
            'prompt_hash'=>'sha-256:'.hash('sha256',$this->compiled->rendered),'skills_hash'=>$this->hasher->hash($this->tenant,'voice-skills',[])->value,
            'tool_schema_hash'=>'sha-256:'.hash('sha256',$this->definition->inputSchema),
            'material_corpus_hash'=>'sha-256:'.$corpus];
    }
    /** @param array<string,mixed> $event */
    public function accept(array $event): AcceptedTurn
    {
        $this->host->event=$event;$e=$this->ingress->open('host:event');$this->input=$e->input;
        $key=$this->input->source->key($this->hasher)->value;
        if (!isset($this->compiledInputs[$key])) {
            $compiled=$this->compile($this->input);$this->compiledInputs[$key]=['compiled'=>$compiled,'plan'=>$this->contextPlan,'target'=>$this->target];
        }
        $cached=$this->compiledInputs[$key];$this->compiled=$cached['compiled'];$this->contextPlan=$cached['plan'];$this->target=$cached['target'];
        $this->ingress->assertCurrent($e);
        $this->accepted=(new IntakeRepository($this->environment))->accept($this->input,$this->compiled->snapshot);
        $this->checkpointState('input:'.$this->accepted->turnId->value);return $this->accepted;
    }
    public function deployment(string $id='scripted-voice/v1'): Deployment
    {
        $caps=[];foreach ([Capability::Text,Capability::Tools] as $cap) { $caps[$cap->value]=new CapabilityEvidence(Support::Supported,Evidence::Documented,new UtcInstant('2026-11-03T00:00:00Z'),new UtcInstant('2030-01-01T00:00:00Z'),'synthetic-only'); }
        return new Deployment($this->ref($id),'scripted','synthetic-account','local',$id,$caps);
    }
    private function compile(TrustedInput $input): CompiledContext
    {
        $keys=new IdentityKeys($this->hasher);$turn=$keys->turn($input->source);$run=$keys->run($turn);$blocks=[];
        $raw=json_decode($this->vault->get($input->context,$this->host->request('host:event')->transcript->textRef),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($raw)) { throw new \UnexpectedValueException('Immutable text required'); }
        $items=[['policy','policy','Mandatory current host guards.'],['working_state','untrusted',json_encode($this->host->state(),JSON_THROW_ON_ERROR)],['input','untrusted',Host::string($raw,'text')]];
        foreach ($items as [$kind,$trust,$text]) {
            $blocks[]=new ContextBlock($this->ref('block:'.$kind),$kind,$this->ref('source:'.$kind),'voice-state:'.$this->host->revision(),$this->ref('voice-host'),$trust,
                'private','voice.assist',$this->ref('content:'.$kind),$text,new UtcInstant('2030-01-01T00:00:00Z'),true,100,$this->hasher,[],new Provenance());
        }
        $compiler=new ReadContextCompiler($this->host,$this->hasher);
        $this->contextPlan=$compiler->buildPlan($input->context,$turn,$run,$this->bundle,$blocks,$this->host->now());
        $this->target=new ContextTarget($this->deployment()->ref,8192,256,128,256);
        $counter=new class($this->deployment()->ref) implements TokenCounter {
            public function __construct(private Ref $target) {}
            public function deployment(): Ref { return $this->target; }
            public function version(): string { return 'synthetic-utf8-byte-bound/v1'; }
            public function confidence(): string { return 'conservative'; }
            public function count(string $rendered): int { return strlen($rendered); }
        };
        $compiled=$compiler->finalize($this->contextPlan,$this->target,$counter,$this->host->now());
        $this->db->execute('INSERT INTO '.$this->db->table('voice_demo_snapshots').' VALUES(:tenant,:id,:revision,:context,CAST(:manifest AS jsonb)) ON CONFLICT DO NOTHING',
            ['tenant'=>$this->tenant->value,'id'=>$compiled->snapshot->ref->value,'revision'=>$compiled->snapshot->revision,'context'=>$this->host->revision(),'manifest'=>$compiled->manifestJson]);
        return $compiled;
    }
    public function makePlan(?Deployment $deployment=null): InvocationPlan
    {
        $keys=new IdentityKeys($this->hasher);$id=$keys->invocation($this->accepted->runId,'voice-answer',1);$deployment??=$this->deployment();
        $request=new ModelRequest($id,[new ModelMessage(MessageRole::User,[ContentPart::text($this->compiled->rendered)])],$this->compiled->snapshot->ref,$this->compiled->snapshot->contentHash,
            $this->ref('credential:synthetic'),new UtcInstant('2030-01-01T00:00:00Z'),$this->guard,
            tools:$this->propose?[new ToolDefinition('voice_read','Synthetic permitted read',new JsonObject(['type'=>'object','properties'=>['query'=>['type'=>'string','const'=>'fixture']],'required'=>['query'],'additionalProperties'=>false]))]:[],generation:new GenerationOptions(256));
        $p=new InvocationPlan($this->accepted->runId,$this->accepted->turnId,'voice-answer',$request,$this->ref('request:'.$id->value),$deployment,$this->compiled->snapshot,
            $this->bundle,$this->ref('business:'.$this->accepted->turnId->value),$this->ref('conversation:voice'),$this->propose?['voice_read'=>$this->definition->ref]:[],new ContinuationPolicy(4,1,2,1,8192),strlen($this->compiled->rendered)+256,null,null,$this->hasher);
        $json=json_encode($request,JSON_THROW_ON_ERROR);$this->vault->put($this->input->context,$p->requestRef,new PayloadHash('sha-256:'.hash('sha256',$json)),$json);return $p;
    }
    public function runtime(): AdvanceRun { return new AdvanceRun(new InvocationRepository($this->environment),new Planner($this),new Budget($this),$this->guard,$this->vault,new Actions($this),$this->hasher); }
    public function drive(): InvocationCheckpoint
    {
        if ($this->checkpoint!==null && $this->checkpoint->lease->runId->value===$this->accepted->runId->value) { return $this->checkpoint; }
        $this->plan=$this->makePlan();$run=(new RunRepository($this->environment))->get($this->input->context,$this->accepted->runId)??throw new \RuntimeException('Missing accepted run');
        if ($run->status->value==='accepted') { $run=(new RunRepository($this->environment))->beginPreparation($this->input->context,$this->compiled->snapshot,$run->id,$run->version); }
        $claim=(new ClaimRepository($this->environment,new ClaimPolicy(300000)))->claim($this->input->context,$this->compiled->snapshot,$run->id,$run->version)??throw new \RuntimeException('Unavailable claim');
        $this->lease=new AdvanceLease($run->id,$claim->state->version,$claim->state->fencingEpoch,$claim->owner);
        return $this->checkpoint=$this->runtime()->advance($this->input->context,$this->lease,$this->plan);
    }
    /** @return list<Segment> */
    public function output(): array
    {
        $saved=$this->drive();$ref=$saved->data['result_ref']??null;
        if (!is_string($ref)) { throw new \RuntimeException('No persisted model response'); }
        $data=json_decode($this->vault->get($this->input->context,$this->ref($ref)),true,128,JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['response']??null) || !is_array($data['response']['parts']??null)) { throw new \UnexpectedValueException('Missing response parts'); }
        $this->response=new Response($this->input->context,$this->assistant,$this->accepted->turnId,$this->accepted->runId,$this->ref('response:'.$this->accepted->runId->value),1,
            $this->ref('conversation:voice'),$this->host->revision(),$this->host->outputPolicy(),$this->hasher);
        $pins=new GuardSnapshot($this->contextPlan,$this->compiled,$this->target,$this->hasher);$policy=new Output($this);
        $stream=new DisclosureStream($this->input->context,$pins,EntryPoint::Voice,$this->ref($this->plan->request->invocationId->value),$this->response->response,1,
            new BoundaryGuard($this->host,$policy,$this->hasher,$this->host),$policy,$policy,$this->hasher);
        $segments=[];$parts=$data['response']['parts'];
        foreach ($parts as $i=>$part) {
            if (!is_array($part) || !is_int($i)) { throw new \UnexpectedValueException('Text part required'); }$text=Host::string($part,'text');
            $payload=$stream->push(new GeneratedChunk($this->ref($this->plan->request->invocationId->value),$this->response->response,$i+1,$text,true,$i===count($parts)-1))??throw new \RuntimeException('Unreleased segment');
            $s=new Segment($this->response,'segment:'.($i+1),$i+1,$this->ref('display:'.$this->accepted->runId->value.':'.$i),$payload->hash,strlen($text),
                $this->ref('speech:'.$this->accepted->runId->value.':'.$i),$payload->hash,strlen($text),[0,strlen($text)],$this->hasher);
            $this->vault->put($this->input->context,$s->speakableRef,new PayloadHash('sha-256:'.hash('sha256',json_encode(['text'=>$text],JSON_THROW_ON_ERROR))),json_encode(['text'=>$text],JSON_THROW_ON_ERROR));
            $this->host->payloads[$s->key->value]=$payload;$this->delivery->register($s);$segments[]=$s;
        }
        return $this->segments=$segments;
    }
    /** Materialize only receipt-bounded speech; the whole generated ref is never a heard summary. */
    public function heard(): string
    {
        $out=[];foreach ($this->delivery->history($this->response??throw new \RuntimeException('No response')) as $part) {
            $ref=$part['text_ref']??null;$through=$part['through_bytes']??null;
            if (!is_array($ref) || !is_int($through)) { throw new \UnexpectedValueException('Bounded history required'); }
            $text=json_decode($this->vault->get($this->input->context,$this->ref(Host::string($ref,'id'))),true,512,JSON_THROW_ON_ERROR);
            if (!is_array($text)) { throw new \UnexpectedValueException('Missing speech slice'); }$out[]=substr(Host::string($text,'text'),0,$through);
        }return implode(' ',$out);
    }
    /** Host-facing result of the actual guarded queue call, without extending dated wire statuses. */
    public function queueCommand(Segment $segment,UiCommand $command): string
    {
        $state='queued';
        try { $this->delivery->queue($segment,$command); }
        catch (ContractViolation $error) {
            if ($error->errorCode!==ErrorCode::StaleContext
                || new \DateTimeImmutable($command->expiresAt->value)>new \DateTimeImmutable($this->host->now()->value)
                || $command->contextRevision!==$this->host->revision() || $segment->response->contextRevision!==$this->host->revision()) { throw $error; }
            $segment->response->context->assertCurrent($this->host);$state='expired';
        }
        $this->db->execute('INSERT INTO '.$this->db->table('voice_demo_checkpoints').' VALUES(:tenant,:id,CAST(:state AS jsonb)) ON CONFLICT DO NOTHING',
            ['tenant'=>$this->tenant->value,'id'=>'command:'.$command->id->value.':'.$state,'state'=>json_encode(['command_state'=>$state,'command_ref'=>$command->id->toArray()],JSON_THROW_ON_ERROR)]);
        return $state;
    }
    /** @param array<string,scalar|null> $changes */
    public function change(array $changes,string $checkpoint): void
    {
        foreach ($changes as $field=>$value) {
            if (!in_array($field,['subject_ref','locale','context_revision','media_epoch','policy_revision','recording','memory','export','text_allowed','allowed'],true)) { throw new \InvalidArgumentException('Unknown scripted owner field'); }
            if (in_array($field,['context_revision','media_epoch'],true) && (!is_int($value) || $value<C::number($this->host->state(),$field))) {
                throw new \InvalidArgumentException('Scripted owner revisions cannot move backwards');
            }
            $this->db->execute('UPDATE '.$this->db->table('voice_demo_state').' SET "'.$field.'"=:value WHERE tenant_id=:tenant',
                ['value'=>is_bool($value)?($value?'true':'false'):$value,'tenant'=>$this->tenant->value]);
        }$this->checkpointState($checkpoint);
    }
    private function checkpointState(string $id): void
    {
        $this->db->execute('INSERT INTO '.$this->db->table('voice_demo_checkpoints').' VALUES(:tenant,:id,CAST(:state AS jsonb)) ON CONFLICT DO NOTHING',
            ['tenant'=>$this->tenant->value,'id'=>$id,'state'=>json_encode($this->host->state(),JSON_THROW_ON_ERROR)]);
    }
}
