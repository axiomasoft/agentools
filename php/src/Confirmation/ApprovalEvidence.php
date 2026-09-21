<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Tool\DescriptorDigest;

final readonly class ApprovalEvidence
{
    /** @param list<string> $approverIds */
    public function __construct(
        public string $tenantId,
        public string $proposalId,
        public int $proposalVersion,
        public string $actionId,
        public string $toolName,
        public ArgumentsFingerprint $argsFingerprint,
        public DescriptorDigest $descriptorDigest,
        public string $policyId,
        public string $policyRevision,
        public ApprovalRequirement $requirement,
        public string $proposerId,
        public string $commitPrincipal,
        public array $approverIds,
        public \DateTimeImmutable $expiresAt,
        public string $status = 'approved',
    ) {
        if (in_array('', [$tenantId, $proposalId, $actionId, $toolName, $policyId, $policyRevision, $proposerId, $commitPrincipal], true) || $proposalVersion < 1) {
            throw new \InvalidArgumentException('Approval evidence requires complete binding identities.');
        }
        if (!in_array($status, ['approved', 'revoked'], true)) {
            throw new \InvalidArgumentException('Approval evidence status is closed.');
        }
        if (count($approverIds) !== count(array_unique($approverIds)) || in_array('', $approverIds, true)) {
            throw new \InvalidArgumentException('Approver identities must be unique non-empty strings.');
        }
        if ($requirement->separateFromProposer && in_array($proposerId, $approverIds, true)) {
            throw new \InvalidArgumentException('The proposer cannot be a counted approver.');
        }
    }

    public function matches(ConfirmationRecord $record): bool
    {
        $proposal = $record->proposal;
        $policy = $record->policy;

        return $this->tenantId === $record->tenantId
            && $this->proposalId === $proposal->proposalId
            && $this->proposalVersion === $proposal->proposalVersion
            && $this->actionId === $proposal->actionId
            && $this->toolName === $proposal->toolName
            && $this->argsFingerprint->value === $proposal->argsFingerprint->value
            && $this->descriptorDigest->value === $proposal->descriptorDigest->value
            && $this->policyId === $policy->policyId
            && $this->policyRevision === $policy->revision
            && $this->requirement->equals($policy->approvalRequirement)
            && $this->proposerId === $proposal->proposerId
            && $this->commitPrincipal === $policy->commitPrincipal;
    }
}
