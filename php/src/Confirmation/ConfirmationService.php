<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

use AgenTools\Contracts\ApprovalEvidenceReader;
use AgenTools\Contracts\AuthenticatedActorProvider;
use AgenTools\Contracts\AuthorizationContextResolver;
use AgenTools\Contracts\Clock;
use AgenTools\Contracts\ConfirmationAuthorizer;
use AgenTools\Contracts\ConfirmationPolicyResolver;
use AgenTools\Contracts\ConfirmationRecordStore;
use AgenTools\Contracts\ConfirmationTokenStore;
use AgenTools\Contracts\ToolDescriptorRegistry;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Schema\Canonicalizer;
use AgenTools\Tool\DescriptorDigest;
use AgenTools\Tool\DescriptorDrift;

/**
 * Supported confirmation boundary.
 *
 * Security-critical values are resolved only from constructor-injected ports.
 */
final readonly class ConfirmationService
{
    public function __construct(
        private ConfirmationProtocol $protocol,
        private TokenKeyProvider $keys,
        private ConfirmationTokenStore $store,
        private string $issuer,
        private string $audience,
        private AuthenticatedActorProvider $actors,
        private Clock $clock,
        private ToolDescriptorRegistry $descriptors,
        private AuthorizationContextResolver $contexts,
        private ConfirmationPolicyResolver $policies,
        private ConfirmationRecordStore $records,
        private ApprovalEvidenceReader $approvals,
        private ConfirmationAuthorizer $authorizer,
        private Canonicalizer $canonicalizer,
    ) {
        if ($issuer === '' || $audience === '') {
            throw new \InvalidArgumentException('Issuer and audience are required.');
        }
    }

    /** @param array<string, mixed> $preview */
    public function prepare(
        string $actionId,
        string $toolName,
        mixed $arguments,
        array $preview,
    ): ConfirmationToken {
        if ($actionId === '' || $toolName === '') {
            throw ConfirmationFailure::contextMismatch();
        }

        $actor = $this->actors->currentActor();
        $now = $this->clock->now();
        $descriptor = $this->descriptors->descriptor($toolName);
        if ($descriptor === null || $descriptor->name !== $toolName) {
            throw ConfirmationFailure::contextMismatch();
        }

        $context = $this->contexts->resolveContext($actor, $actionId, $toolName);
        if ($context->tenantId !== $actor->tenantId) {
            throw ConfirmationFailure::contextMismatch();
        }

        $policy = $this->policies->resolvePolicy($actor, $context, $actionId, $toolName);
        if ($policy === null || !$policy->allowsCommit || $policy->validUntil <= $now) {
            throw ConfirmationFailure::policyDenied();
        }

        $proposal = new Proposal(
            proposalId: 'cnf-' . bin2hex(random_bytes(16)),
            proposalVersion: 1,
            actionId: $actionId,
            toolName: $toolName,
            proposerId: $actor->id,
            principal: $policy->commitPrincipal,
            argsFingerprint: ArgumentsFingerprint::fromArguments($arguments, $this->canonicalizer),
            descriptorDigest: DescriptorDigest::for($descriptor, $this->canonicalizer),
            expiresAt: $now->modify('+' . $policy->confirmationTtlSeconds . ' seconds'),
            preview: $preview,
        );
        $token = $this->protocol->prepare($proposal, $now, $actor->tenantId);
        $this->records->save(new ConfirmationRecord(
            $actor->tenantId,
            $proposal,
            $context,
            $policy,
            ConfirmationRecord::digestToken($token->value),
        ));

        return $token;
    }

    public function commit(string $token, mixed $arguments): ConfirmationToken
    {
        $actor = $this->actors->currentActor();
        $now = $this->clock->now();
        try {
            $envelope = TokenEnvelope::parse($token, $this->keys, $now, $this->issuer, $this->audience);
        } catch (ConfirmationFailure $failure) {
            throw $failure;
        }
        $parsed = ConfirmationToken::fromEnvelope($envelope);

        $record = $this->records->load($parsed->proposalId, $parsed->proposalVersion);
        if ($record === null) {
            throw ConfirmationFailure::recordMissing();
        }

        $proposal = $record->proposal;
        if ($record->tenantId !== $actor->tenantId
            || $record->context->tenantId !== $actor->tenantId
            || $envelope->tenantId !== $actor->tenantId
            || $record->tokenDigest !== ConfirmationRecord::digestToken($token)
            || $parsed->proposalId !== $proposal->proposalId
            || $parsed->proposalVersion !== $proposal->proposalVersion
            || $parsed->actionId !== $proposal->actionId
            || $parsed->toolName !== $proposal->toolName
            || $parsed->principal !== $proposal->principal) {
            throw ConfirmationFailure::contextMismatch();
        }

        if ($parsed->expiresAt <= $now || $proposal->expiresAt <= $now) {
            throw ConfirmationFailure::expired();
        }
        $actual = ArgumentsFingerprint::fromArguments($arguments, $this->canonicalizer);
        if ($actual->value !== $proposal->argsFingerprint->value || $parsed->argsFingerprint->value !== $actual->value) {
            throw ConfirmationFailure::argsMismatch();
        }

        $descriptor = $this->descriptors->descriptor($proposal->toolName);
        if ($descriptor === null) {
            throw ConfirmationFailure::contextMismatch();
        }
        $currentDigest = DescriptorDigest::for($descriptor, $this->canonicalizer);
        if ($currentDigest->value !== $proposal->descriptorDigest->value || $parsed->descriptorDigest->value !== $currentDigest->value) {
            throw new DescriptorDrift($proposal->descriptorDigest, $currentDigest);
        }

        $this->store->consume(
            $envelope->tenantId,
            $envelope->jti,
            hash('sha256', $token),
            TokenPurpose::Confirmation->value,
            $parsed->expiresAt,
            $now,
        );

        try {
            $policy = $this->policies->resolvePolicy($actor, $record->context, $proposal->actionId, $proposal->toolName);
            if ($policy === null || !$policy->allowsCommit || $policy->validUntil <= $now) {
                throw ConfirmationFailure::policyDenied();
            }
            if (!$policy->matches($record->policy)) {
                throw ConfirmationFailure::policyChanged();
            }
            if ($actor->id !== $policy->commitPrincipal) {
                throw ConfirmationFailure::principalMismatch();
            }

            $requirement = $policy->approvalRequirement;
            if ($requirement->mode !== ApprovalRequirement::MODE_NONE) {
                $evidence = $this->approvals->read($record);
                if ($evidence === null) {
                    throw ConfirmationFailure::approvalMissing();
                }
                if ($evidence->status !== 'approved' || $evidence->expiresAt <= $now) {
                    throw ConfirmationFailure::approvalStale();
                }
                if (!$evidence->matches($record)) {
                    throw ConfirmationFailure::approvalMismatch();
                }
                if (count($evidence->approverIds) < $requirement->quorum) {
                    throw ConfirmationFailure::quorumInsufficient();
                }
                if ($requirement->separateFromProposer && in_array($proposal->proposerId, $evidence->approverIds, true)) {
                    throw ConfirmationFailure::separationViolation();
                }
            }

            $request = new AuthorizationRequest(
                actor: $actor,
                tenantId: $record->tenantId,
                resourceId: $record->context->resourceId,
                actionId: $proposal->actionId,
                toolName: $proposal->toolName,
                proposalId: $proposal->proposalId,
                proposalVersion: $proposal->proposalVersion,
                argsFingerprint: $actual,
                descriptorDigest: $currentDigest,
                policyId: $policy->policyId,
                policyRevision: $policy->revision,
                environmentAttributes: $record->context->environmentAttributes,
                riskAttributes: $record->context->riskAttributes,
                delegationId: $record->context->delegationId,
            );
            if (!$this->authorizer->authorize($request)->isAllowed()) {
                throw ConfirmationFailure::authorizationDenied();
            }

            $this->store->refineOutcome($envelope->tenantId, $envelope->jti, 'authorized');
            return $parsed;
        } catch (ConfirmationFailure $failure) {
            $this->refineOutcomeForFailure($envelope->tenantId, $envelope->jti, $failure);
            throw $failure;
        }
    }

    private function refineOutcomeForFailure(string $tenantId, string $jti, ConfirmationFailure $failure): void
    {
        $code = $failure->error->code;
        if (str_contains($code, 'policy')) {
            $this->store->refineOutcome($tenantId, $jti, 'policy_denied');
            return;
        }
        if (str_contains($code, 'authorization')) {
            $this->store->refineOutcome($tenantId, $jti, 'authorization_denied');
        }
    }
}
