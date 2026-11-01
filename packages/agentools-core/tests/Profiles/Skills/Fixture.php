<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Profiles\Skills;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, RunId, SessionId, TenantId};
use AgenToolsPlatform\Core\Profiles\{DefinitionRef, OwnerScope, Provenance, ResourceKind, ResourceRef, UseBoundary};
use AgenToolsPlatform\Core\Profiles\Composition\{OperationalRole, SafetyFloor};
use AgenToolsPlatform\Core\Profiles\Skills\{Classification, Compatibility, ContentKind, ContentStore, Fragment, FragmentRef, ManifestCatalog,
    Policy, PreparedSelection, PublicationStatus, PublishedSkill, Publisher, Qualification, QualificationDecision,
    QualificationTuple, Registry, Router, RuntimeScope, SelectionFailure, SelectionRequest, SelectionSnapshot,
    SkillManifest, TokenCounter, TokenEstimate};
use AgenToolsPlatform\Tests\Profiles\Merge\{Fixture as MergeFixture, Proof};

final class Store implements ContentStore
{
    /**
     * @var array<string, Fragment>
     */ public array $entries=[];
    /**
     * @var list<FragmentRef>
     */ private array $loads=[];
    public ?Fragment $corrupted=null;
    public ?\Closure $afterLoad=null;
    public function put(Fragment $fragment): void
    {
        $key=$fragment->ref->key();
        if (isset($this->entries[$key]) && $this->entries[$key]->ref->toArray() !== $fragment->ref->toArray()) { throw new IntegrityConflict('Fragment identity conflict'); }
        $this->entries[$key]=$fragment;
    }
    /**
     * @return list<FragmentRef>
     */
    public function loaded(): array { return $this->loads; }
    public function resetLoads(): void { $this->loads=[]; }
    public function load(FragmentRef $ref): Fragment
    {
        $this->loads[]=$ref;
        if ($this->afterLoad !== null) { ($this->afterLoad)(); }
        return $this->corrupted ?? $this->entries[$ref->key()] ?? throw new SelectionFailure('CONTENT_UNKNOWN','unknown');
    }
}

final class Counter implements TokenCounter
{
    public bool $unknown=false;
    public bool $oversized=false;
    public int $calls=0;
    public function __construct(public ResourceRef $target) {}
    public function revision(): DefinitionRef { return new DefinitionRef('byte-upper-bound','1',MergeFixture::hash('counter/v1')); }
    public function deployment(): ResourceRef { return $this->target; }
    public function estimate(string $text): TokenEstimate
    {
        ++$this->calls;
        return $this->unknown ? TokenEstimate::unknown() : TokenEstimate::bounded($this->oversized ? 20000 : strlen($text));
    }
}

final class HostPolicy implements Policy
{
    public bool $denied=false;
    public bool $publishDenied=false;
    public bool $unknown=false;
    public bool $unknownStatus=false;
    public bool $expired=false;
    /** @var array<string, bool> */ public array $deniedMetadata=[];
    public QualificationDecision $decision=QualificationDecision::Qualified;
    /**
     * @var array<string, bool>
     */ public array $revoked=[];
    public int $qualificationCalls=0;
    public int $qualificationChecks=0;
    public int $authorizations=0;
    public function authorize(SelectionRequest $request, UseBoundary $boundary): void
    {
        ++$this->authorizations;
        if ($this->unknown) { throw new \RuntimeException('private diagnostic secret_sentinel'); }
        if ($this->denied) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
    public function authorizePublication(SelectionRequest $request, SkillManifest $manifest): void
    {
        if ($this->publishDenied || $this->denied) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
    public function status(SelectionRequest $request, PublishedSkill $skill, UseBoundary $boundary): PublicationStatus
    {
        if ($this->unknownStatus) { return PublicationStatus::Unknown; }
        return isset($this->revoked[$skill->manifest->dependency->ref->version->id]) ? PublicationStatus::Revoked : PublicationStatus::Published;
    }
    public function metadata(SelectionRequest $request, SkillManifest $manifest, UseBoundary $boundary): void
    {
        if (isset($this->deniedMetadata[$manifest->dependency->ref->version->id])
            || str_contains(json_encode($manifest->toArray(),JSON_THROW_ON_ERROR),'secret_sentinel')) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
    public function fragment(SelectionRequest $request, SkillManifest $manifest, Fragment $fragment, UseBoundary $boundary): void
    {
        if ($this->denied) { throw new ContractViolation(ErrorCode::AccessDenied); }
        foreach (['secret_sentinel','history_sentinel','corpus_sentinel','curriculum_sentinel','tool_catalogue_sentinel'] as $marker) {
            if (str_contains($fragment->text,$marker)) { throw new ContractViolation(ErrorCode::AccessDenied); }
        }
    }
    public function qualify(SelectionRequest $request, SkillManifest $manifest, QualificationTuple $tuple): Qualification
    {
        ++$this->qualificationCalls;
        return new Qualification(new ResourceRef(OwnerScope::tenant($request->run->tenant),ResourceKind::ExecutionPolicy,
            'qualification.'.substr($tuple->hash->value,8,16),'1',MergeFixture::hash('evidence:'.$tuple->hash->value)),
            $tuple->hash,$this->decision,'contract-scripted');
    }
    public function assertQualification(SelectionRequest $request, PublishedSkill $skill, QualificationTuple $tuple, UseBoundary $boundary): void
    {
        ++$this->qualificationChecks;
        if ($this->expired) { throw new SelectionFailure('QUALIFICATION_EXPIRED','denied'); }
        if ($tuple->hash->value !== $skill->qualification->tupleHash->value) { throw new SelectionFailure('QUALIFICATION_TUPLE_MISMATCH','denied'); }
    }
}

final class TenantCatalog implements ManifestCatalog
{
    /**
     * @var array<string, PublishedSkill>
     */ public array $entries=[];
    public ?PublishedSkill $corrupted=null;
    public int $gets=0;
    public function put(PublishedSkill $entry): void
    {
        $key=$entry->manifest->dependency->ref->key();
        if (isset($this->entries[$key]) && $this->entries[$key]->manifest->dependency->toArray() !== $entry->manifest->dependency->toArray()) {
            throw new IntegrityConflict('Published skill version is immutable');
        }
        $this->entries[$key]=$entry;
    }
    public function get(ResourceRef $pin, TenantId $tenant): PublishedSkill
    {
        ++$this->gets; $pin->owner->assertVisible($tenant);
        return $this->corrupted ?? $this->entries[$pin->key()] ?? throw new SelectionFailure('MANIFEST_UNKNOWN','unknown');
    }
}

final class Fixture
{
    public MergeFixture $merge;
    public RuntimeScope $scope;
    public Store $store;
    public Counter $counter;
    public HostPolicy $policy;
    public TenantCatalog $tenantCatalog;
    public Registry $registry;
    public Publisher $publisher;
    public Router $router;
    public function __construct(string $tenant='tenant-a')
    {
        $this->merge=new MergeFixture($tenant); $bundle=$this->merge->bundle();
        $pinned=Proof::compiled($this->merge->compiler->pinned($bundle));
        $deployment=array_values($pinned->input()->deployments)[0]->ref;
        $this->scope=new RuntimeScope($bundle,$pinned,$this->merge->operational(OperationalRole::Host),
            $this->merge->operational(OperationalRole::Session),$this->merge->operational(OperationalRole::Live),$deployment,
            new ResourceRef(OwnerScope::platform(),ResourceKind::ModelRevision,'scripted.model','1',MergeFixture::hash('model/v1')),
            [new DefinitionRef('technical.skills','1',MergeFixture::hash('technical/v1')),new DefinitionRef('task.support','1',MergeFixture::hash('task/v1'))],MergeFixture::hash('corpus/v1'));
        $this->store=new Store(); $this->counter=new Counter($deployment); $this->policy=new HostPolicy(); $this->tenantCatalog=new TenantCatalog();
        $this->registry=new Registry([],$this->tenantCatalog);
        $this->publisher=new Publisher($this->store,$this->policy,$this->counter,$this->merge->composer,$this->merge->json);
        $this->router=new Router($this->registry,$this->store,$this->policy,$this->counter,$this->merge->composer,$this->merge->json);
    }
    /**
     * @param list<ResourceRef>|null $pins
     * @param list<ResourceRef> $routes
     * @param array<array-key, mixed> $context
     */
    public function request(?array $pins=null, array $routes=[], array $context=['goal'], string $run='run-a',
        ?RuntimeScope $scope=null, int $tokens=4096, int $limit=8): SelectionRequest
    {
        $pins ??= array_map(static fn (PublishedSkill $s): ResourceRef => $s->manifest->dependency->ref,array_values($this->tenantCatalog->entries));
        return new SelectionRequest(new RunId($this->merge->tenant,$run),new SessionId($this->merge->tenant,'session'),$scope ?? $this->scope,
            'support.read','support.read',$context,MergeFixture::hash('fixed-context'),$pins,$routes,$limit,$tokens);
    }
    public function fragment(string $id='skill.support', string $version='1', ?string $text=null,
        ContentKind $kind=ContentKind::Fragment, Classification $classification=Classification::Restricted): Fragment
    {
        return new Fragment(OwnerScope::tenant($this->merge->tenant),'fragment.'.$id,$version,$text ?? 'Only full fragment '.$id.'@'.$version,
            $kind,$classification,new Provenance('thirdparty:fixture','validation:scripted','evaluation:scripted'),$this->merge->json);
    }
    /**
     * @param list<DefinitionRef>|null $tools
     * @param array<array-key, mixed> $context
     */
    public function manifest(string $id='skill.support', string $version='1', ?Fragment $fragment=null,
        ?array $tools=null, array $context=['goal'], int $budget=256, ?OwnerScope $owner=null): SkillManifest
    {
        $fragment ??= $this->fragment($id,$version);
        $tools ??= array_map(static fn ($tool): DefinitionRef => $tool->definition,array_values($this->scope->pinned->input()->registry->tools));
        return new SkillManifest($owner ?? OwnerScope::tenant($this->merge->tenant),$id,$version,'support.read',['support.read'],$context,$tools,
            [$fragment->ref],['rubric:support-read'],$budget,SafetyFloor::Strict,
            new Compatibility($this->scope->bundle->definition,$this->scope->bundle->profile,$this->scope->bundle->compiler),
            ['evaluation:task-support'],new Provenance('host:fixture','validation:deterministic','evaluation:task-support'),$this->merge->json);
    }
    /**
     * @phpstan-impure Registers exact immutable content and a scripted publication certificate.
     */
    public function publish(string $id='skill.support', string $version='1', ?Fragment $fragment=null): PublishedSkill
    {
        $fragment ??= $this->fragment($id,$version); $this->store->put($fragment); $manifest=$this->manifest($id,$version,$fragment);
        $entry=$this->publisher->publish($this->request(pins:[$manifest->dependency->ref]),$manifest);
        if (!$entry instanceof PublishedSkill) { throw new \RuntimeException('Fixture publication failed: '.$entry->failureCode); }
        $this->tenantCatalog->put($entry); return $entry;
    }
    public static function selected(SelectionSnapshot|\AgenToolsPlatform\Core\Profiles\Skills\MissingContext|SelectionFailure $result): SelectionSnapshot
    {
        if (!$result instanceof SelectionSnapshot) { throw new \RuntimeException('Selection required'); } return $result;
    }
    public static function prepared(PreparedSelection|\AgenToolsPlatform\Core\Profiles\Skills\MissingContext|SelectionFailure $result): PreparedSelection
    {
        if (!$result instanceof PreparedSelection) { throw new \RuntimeException('Prepared selection required'); } return $result;
    }
    public static function failure(mixed $value, string $code): void
    {
        if (!$value instanceof SelectionFailure || $value->failureCode !== $code) { throw new \RuntimeException('Expected failure '.$code.', got '.json_encode($value,JSON_THROW_ON_ERROR)); }
    }
}
