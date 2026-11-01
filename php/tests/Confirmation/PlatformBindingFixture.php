<?php

declare(strict_types=1);

namespace AgenTools\Tests\Confirmation;

use AgenTools\Confirmation\{ApprovalEvidence, ApprovalRequirement, AuthenticatedActor, AuthorizationContext, AuthorizationDecision, AuthorizationRequest, ConfirmationProtocol, ConfirmationRecord, ConfirmationService, KeyId, KeyState, ResolvedPolicy, SigningKey, TokenKeyProvider, TokenPurpose, VerificationKey};
use AgenTools\TestKit\InMemoryConfirmationTokenStore;
use AgenTools\Contracts\{ApprovalEvidenceReader, AuthenticatedActorProvider, AuthorizationContextResolver, Clock, ConfirmationAuthorizer, ConfirmationPolicyResolver, ConfirmationRecordStore, ToolDescriptorRegistry};
use AgenTools\Schema\Canonicalizer;
use AgenTools\Tool\{ActionProfile, ToolAnnotations, ToolDescriptor};

/** Scripted trusted ports exercising the unchanged hardened SDK boundary. */
final class PlatformBindingFixture implements AuthenticatedActorProvider,AuthorizationContextResolver,ConfirmationPolicyResolver,
    ConfirmationRecordStore,ApprovalEvidenceReader,ConfirmationAuthorizer,ToolDescriptorRegistry,Clock,TokenKeyProvider
{
    public string $actor='actor';
    public string $tenant='tenant-a';
    public string $binding='binding-1';
    public string $revision='1';
    public string $proof='ui';
    public \DateTimeImmutable $instant;
    private ?ConfirmationRecord $saved=null;
    public ConfirmationService $service;
    public function __construct()
    {
        $this->instant=new \DateTimeImmutable('2026-11-01T00:00:00Z');
        $store=new InMemoryConfirmationTokenStore();
        $this->service=new ConfirmationService(new ConfirmationProtocol($this,$store,'platform-tests','confirmation'),$this,$store,'platform-tests','confirmation',
            $this,$this,$this,$this,$this,$this,$this,$this,new Canonicalizer());
    }
    public function now(): \DateTimeImmutable { return $this->instant; }
    public function currentActor(): AuthenticatedActor { return new AuthenticatedActor($this->actor,$this->tenant); }
    public function resolveContext(AuthenticatedActor $actor,string $actionId,string $toolName): AuthorizationContext { return new AuthorizationContext($actor->tenantId,$actionId,['binding_hash'=>$this->binding]); }
    public function resolvePolicy(AuthenticatedActor $actor,AuthorizationContext $context,string $actionId,string $toolName): ResolvedPolicy {
        return new ResolvedPolicy('documents',$this->revision,'actor',new ApprovalRequirement('quorum',1,false),new \DateTimeImmutable('2026-11-02T00:00:00Z'),300);
    }
    public function descriptor(string $toolName): ToolDescriptor {
        return new ToolDescriptor('platform_confirmed_action',"Purpose: Bound write.\nCall when: Host consent exists.\nRequired arguments: recipient document amount\nExample: platform_confirmed_action({})",
            ['type'=>'object'],new ActionProfile('write','confirm',true,null,null,['platform_binding_hash'=>$this->binding]),new ToolAnnotations(false,false,true,false));
    }
    public function save(ConfirmationRecord $record): void { $this->saved=$record; }
    public function load(string $proposalId,int $proposalVersion): ?ConfirmationRecord {
        return $this->saved?->proposal->proposalId===$proposalId && $this->saved->proposal->proposalVersion===$proposalVersion ? $this->saved : null;
    }
    public function read(ConfirmationRecord $record): ?ApprovalEvidence {
        if (!in_array($this->proof,['ui','voice'],true)) { return null; }
        $p=$record->proposal; $policy=$record->policy;
        return new ApprovalEvidence($record->tenantId,$p->proposalId,$p->proposalVersion,$p->actionId,$p->toolName,$p->argsFingerprint,$p->descriptorDigest,
            $policy->policyId,$policy->revision,$policy->approvalRequirement,$p->proposerId,$policy->commitPrincipal,['actor'],$p->expiresAt);
    }
    public function authorize(AuthorizationRequest $request): AuthorizationDecision { return AuthorizationDecision::allow(); }
    public function signingKey(string $tenantId,TokenPurpose $purpose): SigningKey { return new SigningKey(new KeyId('test-v1'),'HS256',str_repeat('s',32)); }
    public function verificationKey(string $tenantId,TokenPurpose $purpose,KeyId $kid): VerificationKey { return new VerificationKey($kid,'HS256',str_repeat('s',32),KeyState::Active); }

    /**
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $case
     */
    public static function rejectsCase(array $arguments,array $case): bool
    {
        $f=new self();
        $token=$f->service->prepare('action-1','platform_confirmed_action',$arguments,['summary'=>'Exact write','changes'=>[$arguments]]);
        if (isset($case['descriptor_binding']) && is_string($case['descriptor_binding'])) { $f->binding=$case['descriptor_binding']; }
        if (isset($case['policy_revision']) && is_string($case['policy_revision'])) { $f->revision=$case['policy_revision']; }
        if (isset($case['actor']) && is_string($case['actor'])) { $f->actor=$case['actor']; }
        if (isset($case['tenant']) && is_string($case['tenant'])) { $f->tenant=$case['tenant']; }
        if (isset($case['proof_source']) && is_string($case['proof_source'])) { $f->proof=$case['proof_source']; }
        if (isset($case['elapsed_seconds']) && is_int($case['elapsed_seconds'])) { $f->instant=$f->instant->modify('+'.$case['elapsed_seconds'].' seconds'); }
        if (($case['replay']??false)===true) { $f->service->commit($token->value,$arguments); }
        try { $f->service->commit($token->value,$case['arguments']??$arguments); }
        catch (\AgenTools\Confirmation\ConfirmationFailure|\AgenTools\Tool\DescriptorDrift) { return true; }
        return false;
    }
}
