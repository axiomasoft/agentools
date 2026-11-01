<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

use AgenToolsPlatform\Core\Common\{ActionReceipt, ActionPhaseMapping, Outcome, Ref};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;

/** Safe projection only. Unresolved owner outcomes stay unknown with the journaled wire status. */
final readonly class ActionView
{
    public string $safeNextStep;

    public function __construct(
        public ActionReceipt $receipt,
        public DefinitionRef $definition,
        string $safeNextStep,
        public ?Ref $resultRef = null,
    ) {
        if (!in_array($safeNextStep, ['status', 'confirm', 'reconcile', 'manual_resolution', 'none'], true)) {
            throw new \InvalidArgumentException('Invalid safe next step');
        }
        // A durable request can outlive both the owner call and local receipt storage.
        // Even when storage cannot journal the loss, applying never implies safe replay.
        $this->safeNextStep = $receipt->wireStatus === 'applying'
            && !in_array($safeNextStep, ['reconcile', 'manual_resolution'], true) ? 'reconcile' : $safeNextStep;
        if ($resultRef !== null) {
            $receipt->actionId->tenant->assertSame($resultRef->tenant);
        }
    }

    public function projectPhase(string $phase, bool $dispatchEligible = false): string
    {
        return ActionPhaseMapping::project($phase, $this->receipt->wireStatus, $dispatchEligible);
    }

    /** Pending/cancelled/rejected are not fabricated owner outcomes. No new dated status. */
    public function outcome(): ?Outcome
    {
        return match ($this->receipt->wireStatus) {
            'completed' => Outcome::Succeeded,
            'failed' => Outcome::Failed,
            'applying', 'reconciliation_required' => Outcome::Unknown,
            default => null,
        };
    }
}
