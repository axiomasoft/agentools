<?php

declare(strict_types=1);

namespace AgenTools\Tests\Confirmation;

use AgenTools\Confirmation\ApprovalEvidence;
use AgenTools\Confirmation\ApprovalRequirement;
use AgenTools\Confirmation\AuthenticatedActor;
use AgenTools\Confirmation\AuthorizationContext;
use AgenTools\Confirmation\ConfirmationRecord;
use AgenTools\Confirmation\Proposal;
use AgenTools\Confirmation\ResolvedPolicy;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Tool\DescriptorDigest;
use PHPUnit\Framework\TestCase;

final class ConfirmationSecurityModelTest extends TestCase
{
    public function testApprovalEvidenceRejectsProposerAsApproverWhenSeparationRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApprovalEvidence(
            tenantId: 'tenant-a',
            proposalId: 'p-1',
            proposalVersion: 1,
            actionId: 'act-1',
            toolName: 'tool',
            argsFingerprint: new ArgumentsFingerprint('sha-256:' . str_repeat('c', 64)),
            descriptorDigest: DescriptorDigest::fromValue('sha-256:' . str_repeat('a', 64)),
            policyId: 'pol',
            policyRevision: '1',
            requirement: new ApprovalRequirement(ApprovalRequirement::MODE_QUORUM, 1, true),
            proposerId: 'user-a',
            commitPrincipal: 'user-b',
            approverIds: ['user-a'],
            expiresAt: new \DateTimeImmutable('+1 hour'),
        );
    }

    public function testConfirmationRecordRequiresMatchingTenant(): void
    {
        $proposal = $this->proposal();
        $this->expectException(\InvalidArgumentException::class);
        new ConfirmationRecord(
            'tenant-b',
            $proposal,
            new AuthorizationContext('tenant-a', 'resource'),
            $this->policy(),
            ConfirmationRecord::digestToken('token-value'),
        );
    }

    private function proposal(): Proposal
    {
        return new Proposal(
            proposalId: 'p-1',
            proposalVersion: 1,
            actionId: 'act-1',
            toolName: 'tool',
            proposerId: 'user-a',
            principal: 'user-a',
            argsFingerprint: new ArgumentsFingerprint('sha-256:' . str_repeat('c', 64)),
            descriptorDigest: DescriptorDigest::fromValue('sha-256:' . str_repeat('b', 64)),
            expiresAt: new \DateTimeImmutable('+1 hour'),
            preview: ['summary' => 's', 'changes' => []],
        );
    }

    private function policy(): ResolvedPolicy
    {
        return new ResolvedPolicy(
            policyId: 'pol',
            revision: '1',
            commitPrincipal: 'user-a',
            approvalRequirement: ApprovalRequirement::none(),
            validUntil: new \DateTimeImmutable('+1 day'),
        );
    }
}
