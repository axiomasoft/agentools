<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Knowledge;

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,IdentityKeys,InputPayload,PayloadHash,UtcInstant};
use AgenToolsPlatform\Core\Context\{CompiledContext,ContextBlock,ContextPlan,ContextTarget,GuardSnapshot,Provenance,ReadContextCompiler,SnapshotPins,SourceKind};
use AgenToolsPlatform\Core\Models\{ContentPart,GenerationOptions,ModelMessage,ModelRequest,MessageRole};
use AgenToolsPlatform\Core\Runtime\{AdvanceLease,AdvanceRun,ContinuationPolicy,GuardedTransport,InvocationPlan};
use AgenToolsPlatform\Core\Security\{BoundaryGuard,EntryPoint,TrustedInput};
use AgenToolsPlatform\Examples\ReadJourney\{Counter,PreflightJournal,SupportJourney};
use AgenToolsPlatform\Knowledge\{Decision,GenericValidator,Integrity,Pipeline,Release,Scope,Wire};
use AgenToolsPlatform\Knowledge\Adapters\Postgres\Repository;
use AgenToolsPlatform\Knowledge\Dependencies\{Node,PostgresGraph};
use AgenToolsPlatform\Knowledge\Retrieval\{Clock,EvidenceSet,Query,ReleasePin,Selection,Service};
use AgenToolsPlatform\Knowledge\Retrieval\Adapters\ArrayCache;
use AgenToolsPlatform\Postgres\Claims\{ClaimPolicy,ClaimRepository};
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Models\InvocationRepository;
use AgenToolsPlatform\Postgres\Runtime\RunRepository;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

/** Golden composition over the existing single AdvanceRun loop and real PG repositories. */
final class KnowledgeJourney
{
    public readonly SupportJourney $host;
    public readonly FixtureDocument $document;
    public readonly Owner $owner;
    public readonly Scope $scope;
    public readonly Repository $repository;
    public readonly PostgresGraph $graph;
    public readonly PgIndex $index;
    public readonly Service $retrieval;
    public readonly HostValidator $validator;
    public readonly ReadContextCompiler $compiler;
    public readonly ContextPolicy $access;
    public ?Release $release=null;
    public ?Query $query=null;
    public ?Selection $selection=null;
    public ?EvidenceSet $evidence=null;
    public ?ContextPlan $contextPlan=null;
    public ?CompiledContext $compiled=null;
    public ?GuardSnapshot $guardSnapshot=null;
    public ?Node $snapshotNode=null;
    public ?InvocationPlan $invocation=null;

    public static function install(IsolatedDatabase $database, string $schema): void
    {
        SupportJourney::install($database,$schema);
        $sql=file_get_contents(dirname(__DIR__,4).'/conformance/platform/knowledge/host.sql');
        if ($sql===false) { throw new \RuntimeException('Knowledge host schema missing'); }
        $database->pdo->exec($sql);
    }
    public function __construct(IsolatedDatabase $database, string $schema, string $fixturePath, ?DomainGold $gold,
        string $runKey='first')
    {
        $this->document=new FixtureDocument($fixturePath);$id=Wire::string($this->document->fixture,'id');
        $this->host=new SupportJourney($database,$schema,'knowledge-'.$id,'knowledge.'.$id.'.'.$runKey);
        $this->scope=new Scope($this->host->tenant->value,$this->host->context->identity->subjectRef->value,
            Wire::string($this->document->fixture,'audience'),$this->host->context->purpose,['knowledge.read']);
        $this->owner=new Owner($this->host,$this->scope->audience);
        $this->repository=new Repository($database->pdo,$schema,$this->owner);$this->repository->install();
        $this->graph=new PostgresGraph($database->pdo,$schema,new GraphPolicy($this->owner));
        $this->index=new PgIndex($database->pdo,$schema);$this->validator=new HostValidator($gold);
        $clock=new class implements Clock { public function now(): int { return time(); } };
        $this->retrieval=new Service($this->repository,$this->owner,$this->owner,$clock,$this->index,cache:new ArrayCache());
        $this->access=new ContextPolicy($this);$this->compiler=new ReadContextCompiler($this->access,$this->host->hasher);
    }
    public function ingest(string $version='v1', ?Release $previous=null): Release
    {
        $pipeline=new Pipeline($this->document,new ScriptedExtractor(),new GenericValidator(['node','fragment','navigation','card']),$this->owner,$this->repository,$this->validator);
        $release=$pipeline->ingest($this->scope,'synthetic:corpus',Wire::string($this->document->fixture,'id').':'.$version,
            $this->document->material($this->scope->tenant,$version),previous:$previous?->material);
        $this->index->add($release);$this->release=$release;return $release;
    }
    public function prepare(?string $queryText=null, int $window=8192): void
    {
        $h=$this->host;$release=$this->release ?? throw new \LogicException('Ingest or pin a release first');
        $pin=new ReleasePin($release->corpus,$release->id,$release->hash(),$release->material,[]);
        $this->query=new Query($this->scope,$h->runId->value,$queryText ?? Wire::string($this->document->fixture,'query'),[$pin],$release->material->locale,
            maxFragments:2,maxBytes:1024,fragmentBytes:512);
        $this->selection=$this->retrieval->retrieve($this->query);$this->evidence=$this->retrieval->disclose($this->query,$this->selection);
        $expiry=new UtcInstant('2030-01-01T00:00:00Z');$blocks=[];
        foreach ([['policy','Only disclose current owner-permitted source text. No source means clarification.'],
            ['profile',Wire::string($this->document->fixture,'role')],['input',$queryText ?? Wire::string($this->document->fixture,'query')]] as [$kind,$text]) {
            $ref=$h->ref('knowledge:'.$kind);
            $blocks[]=new ContextBlock($ref,$kind,$ref,'1',$h->ref('host:knowledge'),$kind==='policy' ? 'policy' : 'untrusted','private',$h->context->purpose,$ref,$text,$expiry,
                in_array($kind,['policy','input'],true),$kind==='profile' ? 30 : 100,$h->hasher);
        }
        foreach ($this->evidence->fragments as $i=>$fragment) {
            $ref=$h->ref('knowledge:evidence:'.$i);$source=$h->ref('knowledge:release:'.$release->id);
            $blocks[]=new ContextBlock($ref,'evidence',$source,$release->material->version,$h->ref($release->material->ownerRef),'untrusted','private',
                $h->context->purpose,$ref,json_encode($fragment,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$expiry,false,10,$h->hasher,[],new Provenance(SourceKind::Retrieval));
        }
        $pins=$this->evidence->fragments===[] ? [] : [['ref'=>$h->ref('knowledge:release:'.$release->id),'version'=>$release->material->version,'hash'=>new PayloadHash('sha-256:'.$release->hash())]];
        $this->contextPlan=$this->compiler->buildPlan($h->context,$h->turnId,$h->runId,$h->bundle,$blocks,$h->guard->now(),new SnapshotPins($pins,retention:'synthetic-test-only'));
        $target=new ContextTarget($h->deployment()->ref,$window,256,128,256);
        $this->compiled=$this->compiler->finalize($this->contextPlan,$target,new Counter($target->deployment),$h->guard->now());
        $this->guardSnapshot=new GuardSnapshot($this->contextPlan,$this->compiled,$target,$h->hasher);
        $bundle=new Node($this->scope->tenant,'bundle',$h->bundle->ref->value,(string)$h->bundle->version,substr($h->bundle->hash->value,8));
        $this->graph->register($this->scope,$bundle);
        $this->snapshotNode=new Node($this->scope->tenant,'snapshot',$this->compiled->snapshot->ref->value,'1',substr($this->compiled->snapshot->contentHash->value,8));
        $parents=[$bundle];
        if ($this->evidence->fragments!==[]) {
            $materialBundle=new Node($this->scope->tenant,'bundle','knowledge-material-bundle:'.$release->id,'1',
                Integrity::digest([$bundle->hash,$release->hash()]));
            $this->graph->register($this->scope,$materialBundle,[$bundle,Node::release($release)]);$parents=[$materialBundle];
        }
        $this->graph->register($this->scope,$this->snapshotNode,$parents);
        $cache=new Node($this->scope->tenant,'cache','compiled:'.$this->compiled->snapshot->ref->value,'1',$this->snapshotNode->hash);
        $this->graph->register($this->scope,$cache,[$this->snapshotNode]);
        $h->db->execute('INSERT INTO '.$h->db->table('demo_snapshots').'(tenant_id,id,revision,manifest) VALUES (:tenant,:id,1,CAST(:manifest AS jsonb)) ON CONFLICT DO NOTHING',
            ['tenant'=>$h->tenant->value,'id'=>$this->compiled->snapshot->ref->value,'manifest'=>$this->compiled->manifestJson]);
        $id=(new IdentityKeys($h->hasher))->invocation($h->runId,'knowledge-answer',1);
        $request=new ModelRequest($id,[new ModelMessage(MessageRole::User,[ContentPart::text($this->compiled->rendered)])],
            $this->compiled->snapshot->ref,$this->compiled->snapshot->contentHash,$h->ref('credential:scripted-knowledge'),new UtcInstant('2030-01-01T00:00:00Z'),
            $h->guard,generation:new GenerationOptions(maxOutputTokens:256));
        $this->invocation=new InvocationPlan($h->runId,$h->turnId,'knowledge-answer',$request,$h->ref('knowledge:request:'.$id->value),$h->deployment(),
            $this->compiled->snapshot,$h->bundle,$h->ref('knowledge:business'),$h->ref('knowledge:reply'),[],new ContinuationPolicy(4,1,2,1,4096),
            strlen($this->compiled->rendered)+256,null,null,$h->hasher);
        $h->activePlan=$this->invocation;
        $json=json_encode(['messages'=>$request->messages,'snapshot'=>$this->compiled->snapshot->toArray()],JSON_THROW_ON_ERROR);
        $h->vault->put($h->context,$this->invocation->requestRef,new PayloadHash('sha-256:'.hash('sha256',$json)),$json);
    }
    public function fresh(): bool
    {
        if ($this->query===null || $this->selection===null || $this->evidence===null) { return false; }
        $current=$this->retrieval->disclose($this->query,$this->selection);
        return $current->status===$this->evidence->status && $current->fragments===$this->evidence->fragments;
    }
    public function assertCurrent(): void
    {
        $h=$this->host;$h->context->assertCurrent($h->host);
        if ($this->owner->current($this->scope)!==Decision::Allowed || !$this->fresh()) { throw new ContractViolation(ErrorCode::AccessDenied); }
        $this->compiler->authorizeReuse($this->contextPlan ?? throw new \LogicException('Prepare first'),
            $this->compiled ?? throw new \LogicException('Prepare first'),$this->access,$h->guard->now());
    }
    /** Selected evidence only; optional fragments pruned by the compiler never enter the answer.
     * @return list<array<string,mixed>>
     */
    public function selectedEvidence(): array
    {
        $compiled=$this->compiled ?? throw new \LogicException('Prepare first');$plan=$this->contextPlan ?? throw new \LogicException('Prepare first');
        $manifest=Wire::object(json_decode($compiled->manifestJson,true,64,JSON_THROW_ON_ERROR));$selected=[];$out=[];
        foreach (Wire::objects($manifest['sections'] ?? null) as $section) { $selected[Wire::string($section,'id')]=true; }
        foreach ($plan->blocks as $block) {
            if ($block->kind==='evidence' && isset($selected[$block->ref->value])) { $out[]=Wire::object(json_decode($block->modelView,true,64,JSON_THROW_ON_ERROR)); }
        }
        return $out;
    }
    public function run(): string
    {
        $h=$this->host;if ($this->compiled===null) { $this->prepare(); }$this->assertCurrent();
        $compiled=$this->compiled ?? throw new \LogicException('Prepare first');$plan=$this->invocation ?? throw new \LogicException('Prepare first');
        $json=json_encode(['query'=>$this->query?->normalized,'fixture'=>Wire::string($this->document->fixture,'id')],JSON_THROW_ON_ERROR);
        $inputRef=$h->ref('knowledge:input:'.$h->runId->value);$hash=new PayloadHash('sha-256:'.hash('sha256',$json));$h->vault->put($h->context,$inputRef,$hash,$json);
        (new IntakeRepository($h->environment))->accept(new TrustedInput($h->context,$h->source,new InputPayload($inputRef,$hash,$h->ref('conversation:synthetic'),$h->ref('message:synthetic'))),$compiled->snapshot);
        $this->graph->recordUse($this->scope,$h->runId->value,[$this->snapshotNode ?? throw new \LogicException('Prepare graph first')]);
        $runs=new RunRepository($h->environment);$run=$runs->get($h->context,$h->runId) ?? throw new \RuntimeException('Intake missing');
        if ($run->status->terminal()) { return $run->status->value; }
        if ($run->status->value==='accepted') { $run=$runs->beginPreparation($h->context,$compiled->snapshot,$h->runId,$run->version); }
        $claim=(new ClaimRepository($h->environment,new ClaimPolicy(300000)))->claim($h->context,$compiled->snapshot,$h->runId,$run->version);
        if ($claim===null) { return 'busy'; }
        $lease=new AdvanceLease($h->runId,$claim->state->version,$claim->state->fencingEpoch,$claim->owner);$h->workerLease=$lease;
        $model=new ScriptedModel($this);$boundary=new Boundary($this);
        $transport=new GuardedTransport($model,$h->context,$this->guardSnapshot ?? throw new \LogicException('Prepare first'),EntryPoint::Text,
            $plan->deployment,$plan->request->credentialRef,[],new BoundaryGuard($h->host,$boundary,$h->hasher,$boundary),new RequestView($this),$h->hasher);
        $runtime=new AdvanceRun(new PreflightJournal($h,new InvocationRepository($h->environment)),$transport,$h->budget,$h->guard,$h->vault,$h->actions,$h->hasher,$model);
        $saved=$runtime->advance($h->context,$lease,$plan);$h->workerLease=$saved->lease;return $saved->stage();
    }
    public function deliver(): string
    {
        $h=$this->host;$prior=$h->barrier;
        $h->barrier=function(string $boundary) use ($prior): void {
            if ($prior!==null) { $prior($boundary); }
            if ($boundary==='delivery') { $this->assertCurrent(); }
        };
        try { $this->assertCurrent();return $h->delivery->deliver(); }
        finally { $h->barrier=$prior; }
    }
}
