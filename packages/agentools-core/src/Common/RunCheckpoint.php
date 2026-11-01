<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

/** Pure transition value, not a store, worker claim or effect dispatcher. */
final readonly class RunCheckpoint
{
    public function __construct(
        public RunId $runId,
        public RunStatus $status,
        public int $version,
        public int $claimEpoch,
        public Certainty $certainty = Certainty::Known,
        public bool $unresolvedEffects = false,
    ) {
        if ($version < 1 || $claimEpoch < 1 || $version > 9007199254740991 || $claimEpoch > 9007199254740991
            || ($status->terminal() && ($certainty === Certainty::Unknown || $unresolvedEffects))) {
            throw new \InvalidArgumentException('Invalid checkpoint version/epoch or terminal uncertainty');
        }
    }

    private function assertCurrent(int $expectedVersion, int $claimEpoch): void
    {
        if ($expectedVersion !== $this->version) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::OptimisticConflict);
        }
        if ($claimEpoch !== $this->claimEpoch) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleClaim);
        }
        if ($this->status->terminal()) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict);
        }
    }

    public function transition(RunStatus $next, int $expectedVersion, int $claimEpoch): self
    {
        $this->assertCurrent($expectedVersion, $claimEpoch);
        if (!$this->status->allows($next) || ($next->terminal() && ($this->certainty === Certainty::Unknown || $this->unresolvedEffects))) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict);
        }
        return new \AgenToolsPlatform\Core\Common\RunCheckpoint($this->runId, $next, $this->version + 1,
            $this->claimEpoch, $this->certainty, $this->unresolvedEffects);
    }

    public function recordUnknown(ErrorCode $reason, int $expectedVersion, int $claimEpoch): self
    {
        $this->assertCurrent($expectedVersion, $claimEpoch);
        if (!$reason->requiresUnknown()) {
            throw new \InvalidArgumentException('An unknown outcome reason is required');
        }
        return new \AgenToolsPlatform\Core\Common\RunCheckpoint($this->runId, $this->status, $this->version + 1,
            $this->claimEpoch, Certainty::Unknown, $this->unresolvedEffects || $reason === ErrorCode::EffectUnknown);
    }

    /** Caller must have verified owner evidence under current operator/worker policy. */
    public function resolved(Ref $evidenceRef, int $expectedVersion, int $claimEpoch): self
    {
        $this->assertCurrent($expectedVersion, $claimEpoch);
        $this->runId->tenant->assertSame($evidenceRef->tenant);
        return new \AgenToolsPlatform\Core\Common\RunCheckpoint($this->runId, $this->status, $this->version + 1,
            $this->claimEpoch, Certainty::Known, false);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId->toArray(),
            'status' => $this->status->value,
            'version' => $this->version,
            'claim_epoch' => $this->claimEpoch,
            'certainty' => $this->certainty->value,
            'unresolved_effects' => $this->unresolvedEffects,
        ];
    }
}
