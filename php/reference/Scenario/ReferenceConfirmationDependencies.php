<?php

declare(strict_types=1);

namespace AgenTools\Reference\Scenario;

use AgenTools\Confirmation\ApprovalEvidence;
use AgenTools\Confirmation\ApprovalRequirement;
use AgenTools\Confirmation\AuthenticatedActor;
use AgenTools\Confirmation\AuthorizationContext;
use AgenTools\Confirmation\AuthorizationDecision;
use AgenTools\Confirmation\AuthorizationRequest;
use AgenTools\Confirmation\ConfirmationRecord;
use AgenTools\Confirmation\ResolvedPolicy;
use AgenTools\Contracts\ApprovalEvidenceReader;
use AgenTools\Contracts\AuthenticatedActorProvider;
use AgenTools\Contracts\AuthorizationContextResolver;
use AgenTools\Contracts\Clock;
use AgenTools\Contracts\ConfirmationAuthorizer;
use AgenTools\Contracts\ConfirmationPolicyResolver;
use AgenTools\Contracts\ConfirmationRecordStore;
use AgenTools\Contracts\ToolDescriptorRegistry;
use AgenTools\Reference\Adapters\RegistryToolPort;
use AgenTools\Tool\ToolDescriptor;

/** Reference-only trusted composition; production adapters remain application-owned. */
final class ReferenceConfirmationDependencies implements
    AuthenticatedActorProvider,
    ApprovalEvidenceReader,
    AuthorizationContextResolver,
    ConfirmationAuthorizer,
    ConfirmationPolicyResolver,
    ConfirmationRecordStore,
    ToolDescriptorRegistry
{
    /** @var array<string, ConfirmationRecord> */
    private array $records = [];

    /** @param list<string> $permissions */
    public function __construct(
        private readonly RegistryToolPort $tools,
        private readonly Clock $clock,
        private readonly string $tenantId,
        private readonly string $actorId,
        private readonly array $permissions,
    ) {
    }

    public function currentActor(): AuthenticatedActor
    {
        return new AuthenticatedActor($this->actorId, $this->tenantId, ['reference_user']);
    }

    public function descriptor(string $toolName): ?ToolDescriptor
    {
        foreach ($this->tools->discover() as $descriptor) {
            if ($descriptor->name === $toolName) {
                return $descriptor;
            }
        }

        return null;
    }

    public function resolveContext(AuthenticatedActor $actor, string $actionId, string $toolName): AuthorizationContext
    {
        return new AuthorizationContext($actor->tenantId, 'day-report', ['channel' => 'reference'], ['side_effect' => 'write']);
    }

    public function resolvePolicy(
        AuthenticatedActor $actor,
        AuthorizationContext $context,
        string $actionId,
        string $toolName,
    ): ?ResolvedPolicy {
        if ($actor->tenantId !== $this->tenantId || $context->tenantId !== $this->tenantId) {
            return null;
        }

        return new ResolvedPolicy(
            'reference-policy',
            '1',
            $this->actorId,
            ApprovalRequirement::none(),
            $this->clock->now()->modify('+1 day'),
        );
    }

    public function save(ConfirmationRecord $record): void
    {
        $this->records[$record->proposal->proposalId . ':' . $record->proposal->proposalVersion] = $record;
    }

    public function load(string $proposalId, int $proposalVersion): ?ConfirmationRecord
    {
        return $this->records[$proposalId . ':' . $proposalVersion] ?? null;
    }

    public function read(ConfirmationRecord $record): ?ApprovalEvidence
    {
        return null;
    }

    public function authorize(AuthorizationRequest $request): AuthorizationDecision
    {
        if ($request->actor->id === $this->actorId
            && $request->tenantId === $this->tenantId
            && $this->permissions !== []) {
            return AuthorizationDecision::allow();
        }

        return AuthorizationDecision::deny();
    }
}
