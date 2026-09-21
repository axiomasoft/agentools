<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

final readonly class ResolvedPolicy
{
    public function __construct(
        public string $policyId,
        public string $revision,
        public string $commitPrincipal,
        public ApprovalRequirement $approvalRequirement,
        public \DateTimeImmutable $validUntil,
        public int $confirmationTtlSeconds = 3600,
        public bool $allowsCommit = true,
    ) {
        if ($policyId === '' || $revision === '' || $commitPrincipal === '' || $confirmationTtlSeconds < 1) {
            throw new \InvalidArgumentException('Resolved policy requires identity, revision, principal and positive TTL.');
        }
    }

    public function matches(self $other): bool
    {
        return $this->policyId === $other->policyId
            && $this->revision === $other->revision
            && $this->commitPrincipal === $other->commitPrincipal
            && $this->approvalRequirement->equals($other->approvalRequirement);
    }
}
