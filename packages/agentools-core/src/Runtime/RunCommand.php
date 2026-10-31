<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, PayloadHash, Ref, RunId, RunStatus};

/** Stable command semantics; version/epoch are attempt fences, not command identity. */
final readonly class RunCommand
{
    public PayloadHash $hash;

    public function __construct(
        public RunId $runId,
        public Ref $commandId,
        public Ref $stepRef,
        public RunStatus $next,
        public int $expectedVersion,
        public int $claimEpoch,
        public ?Ref $cancellationDecision,
        CanonicalHasher $hasher,
    ) {
        foreach ([$commandId, $stepRef, $cancellationDecision] as $ref) {
            if ($ref !== null) {
                $runId->tenant->assertSame($ref->tenant);
            }
        }
        if ($expectedVersion < 1 || $claimEpoch < 1 || $expectedVersion > 9007199254740991 || $claimEpoch > 9007199254740991) {
            throw new \InvalidArgumentException('Positive interoperable fences required');
        }
        $this->hash = $hasher->hash($runId->tenant, 'run-command', [
            'run_id' => $runId->toArray(), 'command_id' => $commandId->toArray(),
            'run_step' => $stepRef->toArray(), 'next' => $next->value,
            'cancellation_decision' => $cancellationDecision?->toArray(),
        ]);
    }
}
