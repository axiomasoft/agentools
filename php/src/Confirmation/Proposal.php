<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Tool\DescriptorDigest;

/** Immutable prepared confirmation proposal; edits create a new instance. */
final readonly class Proposal
{
    /** @param array<string, mixed> $preview */
    public function __construct(
        public string $proposalId,
        public int $proposalVersion,
        public string $actionId,
        public string $toolName,
        public string $proposerId,
        public string $principal,
        public ArgumentsFingerprint $argsFingerprint,
        public DescriptorDigest $descriptorDigest,
        public \DateTimeImmutable $expiresAt,
        public array $preview,
        public ProposalState $state = ProposalState::Pending,
        public ?string $supersededBy = null,
    ) {
        if ($proposalId === '' || $actionId === '' || $toolName === '' || $proposerId === '' || $principal === '' || $proposalVersion < 1) {
            throw new \InvalidArgumentException('A proposal requires identifiers, principal, and a positive version.');
        }
        if (!isset($preview['summary'], $preview['changes']) || !is_string($preview['summary']) || $preview['summary'] === '' || !is_array($preview['changes'])) {
            throw new \InvalidArgumentException('A proposal preview requires summary and changes.');
        }
    }

    public function supersede(string $successorId): self
    {
        if ($successorId === '') {
            throw new \InvalidArgumentException('A superseding proposal id is required.');
        }
        return new self($this->proposalId, $this->proposalVersion, $this->actionId, $this->toolName, $this->proposerId, $this->principal, $this->argsFingerprint, $this->descriptorDigest, $this->expiresAt, $this->preview, ProposalState::Superseded, $successorId);
    }
}
