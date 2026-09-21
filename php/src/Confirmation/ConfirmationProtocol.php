<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

use AgenTools\Contracts\CommitAuthorizer;
use AgenTools\Contracts\ConfirmationTokenStore;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Tool\DescriptorDigest;

/** @deprecated Unsafe expert/legacy primitive. Applications must use ConfirmationService. */
final class ConfirmationProtocol
{
    public function __construct(
        private readonly TokenKeyProvider $keys,
        private readonly ConfirmationTokenStore $store,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $maxLifetimeSeconds = TokenEnvelope::DEFAULT_MAX_LIFETIME_SECONDS,
    ) {
        if ($issuer === '' || $audience === '') {
            throw new \InvalidArgumentException('Issuer and audience are required.');
        }
        if ($maxLifetimeSeconds < 1 || $maxLifetimeSeconds > TokenEnvelope::HARD_MAX_LIFETIME_SECONDS) {
            throw new \InvalidArgumentException('Confirmation lifetime limit must be between 1 second and 7 days.');
        }
    }

    public function prepare(Proposal $proposal, \DateTimeImmutable $now, string $tenantId): ConfirmationToken
    {
        if ($proposal->state !== ProposalState::Pending) {
            throw new \InvalidArgumentException('Only a pending proposal can receive a token.');
        }
        if ($proposal->expiresAt <= $now) {
            throw new \InvalidArgumentException('A proposal requires a future expires_at deadline.');
        }
        if ($proposal->expiresAt->getTimestamp() - $now->getTimestamp() > $this->maxLifetimeSeconds) {
            throw new \InvalidArgumentException('The proposal lifetime exceeds the configured confirmation limit.');
        }
        if ($tenantId === '') {
            throw new \InvalidArgumentException('Tenant id is required to issue a confirmation token.');
        }

        return ConfirmationToken::issueAt1($proposal, $tenantId, $this->keys, $this->issuer, $this->audience, $now);
    }

    /**
     * @deprecated Expert/legacy primitive. Application code must use {@see ConfirmationService}.
     */
    public function verify(string $token, \DateTimeImmutable $now): ConfirmationToken
    {
        return ConfirmationToken::fromEnvelope(
            TokenEnvelope::parse($token, $this->keys, $now, $this->issuer, $this->audience),
        );
    }

    /**
     * @deprecated Unsafe application boundary: security inputs are caller-controlled.
     */
    public function commit(
        string $token,
        ArgumentsFingerprint $actualArguments,
        DescriptorDigest $currentDescriptor,
        string $principal,
        \DateTimeImmutable $now,
        CommitAuthorizer $authorizer,
        ?string $proposerId = null,
        bool $dualControl = false,
        ?DecisionAudit $decision = null,
    ): ConfirmationToken {
        if ($decision !== null) {
            $decision->assertAllowsCommit($actualArguments);
        }
        $envelope = TokenEnvelope::parse($token, $this->keys, $now, $this->issuer, $this->audience);
        $parsed = ConfirmationToken::fromEnvelope($envelope);
        if ($parsed->argsFingerprint->value !== $actualArguments->value) {
            throw ConfirmationFailure::argsMismatch();
        }
        if ($parsed->principal !== $principal) {
            throw ConfirmationFailure::principalMismatch();
        }
        if ($parsed->descriptorDigest->value !== $currentDescriptor->value) {
            throw new \AgenTools\Tool\DescriptorDrift($parsed->descriptorDigest, $currentDescriptor);
        }
        if ($dualControl && $proposerId === $principal) {
            throw new \LogicException('Dual control requires a different approving principal.');
        }

        $this->store->consume(
            $envelope->tenantId,
            $envelope->jti,
            hash('sha256', $token),
            TokenPurpose::Confirmation->value,
            $parsed->expiresAt,
            $now,
        );

        if (!$authorizer->isAllowed($principal, $parsed->toolName)) {
            $this->store->refineOutcome($envelope->tenantId, $envelope->jti, 'authorization_denied');
            throw ConfirmationFailure::authorizationDenied();
        }

        $this->store->refineOutcome($envelope->tenantId, $envelope->jti, 'authorized');
        return $parsed;
    }
}
