<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

use AgenTools\Contracts\CommitAuthorizer;
use AgenTools\Contracts\TokenSigner;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Tool\DescriptorDigest;

/** Protocol-only layer: issues and verifies the signed one-shot gate token. */
final class ConfirmationProtocol
{
    /** @var array<string, true> */
    private array $spentTokens = [];

    public function __construct(private readonly TokenSigner $signer)
    {
    }

    public function prepare(Proposal $proposal, \DateTimeImmutable $now): ConfirmationToken
    {
        if ($proposal->state !== ProposalState::Pending) {
            throw new \InvalidArgumentException('Only a pending proposal can receive a token.');
        }
        if ($proposal->expiresAt <= $now) {
            throw new \InvalidArgumentException('A proposal requires a future expires_at deadline.');
        }
        return ConfirmationToken::issue($proposal, $this->signer);
    }

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
        $parsed = ConfirmationToken::parse($token, $this->signer);
        if ($parsed->argsFingerprint->value !== $actualArguments->value) {
            throw ConfirmationFailure::argsMismatch();
        }
        if ($parsed->expiresAt <= $now) {
            throw ConfirmationFailure::expired();
        }
        if (array_key_exists($parsed->value, $this->spentTokens)) {
            throw ConfirmationFailure::spent();
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

        // A token proves the proposal, never the current permission. Spend it on
        // presentation before the product boundary can reject the invocation.
        $this->spentTokens[$parsed->value] = true;
        if (!$authorizer->isAllowed($principal, $parsed->toolName)) {
            throw new \LogicException('The principal is no longer authorized to commit this tool.');
        }
        return $parsed;
    }
}
