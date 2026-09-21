<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

use AgenTools\Idempotency\ArgumentsFingerprint;

/** Payload for the journal's confirmation_resolved event, never a second store. */
final readonly class DecisionAudit
{
    public function __construct(
        public string $proposalId,
        public string $token,
        public string $decision,
        public string $actorId,
        public string $actorRole,
        public \DateTimeImmutable $decidedAt,
        public string $reason,
        public ArgumentsFingerprint $argsHashAtDecision,
        public ?ArgumentsFingerprint $argsHashAtCommit = null,
    ) {
        if ($decision !== 'approve' && $argsHashAtCommit !== null) {
            throw new \InvalidArgumentException('Only an applied approval may contain args_hash_at_commit.');
        }
    }

    public function assertAllowsCommit(ArgumentsFingerprint $actualArguments): void
    {
        if ($this->decision !== 'approve') {
            throw new \InvalidArgumentException('Only an approval decision can authorize commit.');
        }
        if ($this->argsHashAtCommit === null
            || $this->argsHashAtDecision->value !== $this->argsHashAtCommit->value
            || $this->argsHashAtCommit->value !== $actualArguments->value) {
            throw ConfirmationFailure::argsMismatch();
        }
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return array_filter([
            'proposal_id' => $this->proposalId,
            'token' => $this->token,
            'decision' => $this->decision,
            'actor_id' => $this->actorId,
            'actor_role' => $this->actorRole,
            'decided_at' => $this->decidedAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'reason' => $this->reason,
            'args_hash_at_decision' => $this->argsHashAtDecision->value,
            'args_hash_at_commit' => $this->argsHashAtCommit?->value,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
