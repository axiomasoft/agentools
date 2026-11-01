<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Confirmation;

use AgenTools\Confirmation\{ApprovalEvidence, ApprovalRequirement, AuthenticatedActor, AuthorizationContext, AuthorizationDecision, AuthorizationRequest, ConfirmationFailure, ConfirmationRecord, ConfirmationToken, Proposal, ResolvedPolicy};
use AgenTools\Contracts\{ApprovalEvidenceReader, AuthenticatedActorProvider, AuthorizationContextResolver, ConfirmationAuthorizer, ConfirmationPolicyResolver, ConfirmationRecordStore, ToolDescriptorRegistry};
use AgenTools\Schema\Canonicalizer;
use AgenTools\Tool\{ActionProfile, ToolAnnotations, ToolDescriptor};
use AgenToolsPlatform\Core\Actions\{ActionDefinition, ActionPolicy, ActionRegistry};
use AgenToolsPlatform\Core\Actions\Confirmation\Binding;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** Per-operation constructor ports. No caller supplied policy verdict or approval evidence. */
final class BoundPorts implements AuthenticatedActorProvider, AuthorizationContextResolver, ConfirmationPolicyResolver,
    ConfirmationRecordStore, ApprovalEvidenceReader, ConfirmationAuthorizer, ToolDescriptorRegistry
{
    public ?ConfirmationRecord $record=null;
    public const TOOL='platform_confirmed_action';

    public function __construct(private RepositoryContext $environment, private Binding $binding,
        private \AgenToolsPlatform\Core\Actions\ActionSubmission $submission,
        private ActionDefinition $definition, private ActionRegistry $registry, private ActionPolicy $policy,
        private int $ttl) {}

    public function currentActor(): AuthenticatedActor
    {
        $context=$this->submission->request->context;
        $this->environment->authorize($context);
        return new AuthenticatedActor($context->identity->actorRef->value,$context->identity->tenant->value);
    }

    public function resolveContext(AuthenticatedActor $actor,string $actionId,string $toolName): AuthorizationContext
    {
        if ($actionId!==$this->binding->action->value || $toolName!==self::TOOL) { throw ConfirmationFailure::contextMismatch(); }
        return new AuthorizationContext($actor->tenantId,$actionId,['platform_binding_hash'=>$this->binding->hash->value,
            'scope_hash'=>$this->submission->request->scopeHash->value]);
    }

    public function resolvePolicy(AuthenticatedActor $actor,AuthorizationContext $context,string $actionId,string $toolName): ResolvedPolicy
    {
        $this->environment->authorize($this->submission->request->context);
        $this->registry->executionView($this->definition->ref,$this->policy->current($this->submission->request->context));
        if ($context->resourceId!==$this->binding->action->value || $actionId!==$context->resourceId || $toolName!==self::TOOL
            || ($context->environmentAttributes['platform_binding_hash']??null)!==$this->binding->hash->value) {
            throw ConfirmationFailure::contextMismatch();
        }
        return new ResolvedPolicy('platform-confirmation',$this->submission->request->context->identity->policyRevision,
            $this->submission->request->context->identity->actorRef->value,new ApprovalRequirement('quorum',1,false),
            new \DateTimeImmutable($this->binding->expiresAt->value),$this->ttl);
    }

    public function descriptor(string $toolName): ?ToolDescriptor
    {
        if ($toolName!==self::TOOL) { return null; }
        /** @var array<string,mixed> $input */
        $input=json_decode($this->definition->inputSchema,true,512,JSON_THROW_ON_ERROR);
        return new ToolDescriptor(self::TOOL,"Purpose: Execute a bound registered Action.\nCall when: Authenticated confirmation is available.\nRequired arguments: Registered action input\nExample: platform_confirmed_action({})",
            $input,new ActionProfile('write','confirm',true,$this->definition->requiredScopes,null,
                ['platform_binding_hash'=>$this->binding->hash->value,'definition_ref'=>$this->definition->ref->toArray()]),
            new ToolAnnotations(false,false,true,false));
    }

    public function save(ConfirmationRecord $record): void
    {
        if ($this->record!==null) { throw new \LogicException('Confirmation records are immutable'); }
        $this->record=$record;
    }

    public function load(string $proposalId,int $proposalVersion): ?ConfirmationRecord
    {
        return $this->record?->proposal->proposalId===$proposalId && $this->record->proposal->proposalVersion===$proposalVersion ? $this->record : null;
    }

    /** Reconstruct only from authenticated encrypted bytes and immutable platform bindings. */
    public function restore(ConfirmationToken $token,string $presentation): void
    {
        $actor=$this->currentActor();
        $context=$this->resolveContext($actor,$this->binding->action->value,self::TOOL);
        $policy=$this->resolvePolicy($actor,$context,$this->binding->action->value,self::TOOL);
        /** @var array<string,mixed> $preview */
        $preview=json_decode($presentation,true,512,JSON_THROW_ON_ERROR);
        $this->save(new ConfirmationRecord($actor->tenantId,new Proposal($token->proposalId,$token->proposalVersion,
            $this->binding->action->value,self::TOOL,$actor->id,$actor->id,$token->argsFingerprint,$token->descriptorDigest,
            new \DateTimeImmutable($this->binding->expiresAt->value),$preview),$context,$policy,ConfirmationRecord::digestToken($token->value)));
    }

    public function read(ConfirmationRecord $record): ?ApprovalEvidence
    {
        $db=$this->environment->db;
        $proof=$db->row('SELECT * FROM '.$db->table('confirmation_proofs').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$record->tenantId,'id'=>$record->proposal->proposalId]);
        if ($proof===null || Connection::text($proof,'binding_hash')!==$this->binding->hash->value
            || Connection::text($proof,'actor_ref')!==$record->policy->commitPrincipal) { return null; }
        $state=$db->transaction(fn ()=>(new Journal($this->environment))->read($this->binding->action,true));
        if ($state->seq!==Connection::number($proof,'journal_version') || $state->epoch!==$this->binding->epoch
            || $state->cancellationRequested || $state->status?->value!=='awaiting_confirmation') { return null; }
        $event=$db->row('SELECT kind,payload_json FROM '.$db->table('agentools_events').' WHERE tenant_id=:tenant AND action_id=:action AND seq=:seq',
            ['tenant'=>$record->tenantId,'action'=>$this->binding->action->value,'seq'=>$state->seq]);
        if ($event===null || Connection::text($event,'kind')!=='platform.confirmation.proof') { return null; }
        /** @var array<string,mixed> $payload */
        $payload=json_decode(Connection::text($event,'payload_json'),true,512,JSON_THROW_ON_ERROR);
        foreach (['binding_hash','source_ref','source_hash','actor_ref','channel'] as $field) {
            if (($payload[$field]??null)!==Connection::text($proof,$field)) { return null; }
        }
        if (($payload['proposal_id']??null)!==$record->proposal->proposalId) { return null; }
        $proposal=$record->proposal;
        return new ApprovalEvidence($record->tenantId,$proposal->proposalId,$proposal->proposalVersion,$proposal->actionId,
            $proposal->toolName,$proposal->argsFingerprint,$proposal->descriptorDigest,$record->policy->policyId,$record->policy->revision,
            $record->policy->approvalRequirement,$proposal->proposerId,$record->policy->commitPrincipal,
            [Connection::text($proof,'actor_ref')],new \DateTimeImmutable(Connection::text($proof,'expires_at')));
    }

    public function authorize(AuthorizationRequest $request): AuthorizationDecision
    {
        $this->currentActor();
        $this->registry->executionView($this->definition->ref,$this->policy->current($this->submission->request->context));
        return $request->actor->id===$this->submission->request->context->identity->actorRef->value
            && $request->tenantId===$this->binding->action->tenant->value
            && ($request->environmentAttributes['platform_binding_hash']??null)===$this->binding->hash->value
            ? AuthorizationDecision::allow() : AuthorizationDecision::deny();
    }
}
