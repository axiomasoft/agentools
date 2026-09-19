<?php

declare(strict_types=1);

namespace AgenTools\Reference\Scenario;

/** Counts confirmation protocol boundaries for product-slice proofs. */
final class ConfirmationMetrics
{
    public int $confirmationRequests = 0;
    public int $proposalCount = 0;
    public int $tokenIssueCount = 0;
    public int $tokenConsumeCount = 0;
    public int $decisionEventCount = 0;

    public function recordConfirmationRequest(): void
    {
        ++$this->confirmationRequests;
    }

    public function recordProposal(): void
    {
        ++$this->proposalCount;
    }

    public function recordTokenIssue(): void
    {
        ++$this->tokenIssueCount;
    }

    public function recordTokenConsume(): void
    {
        ++$this->tokenConsumeCount;
    }

    public function recordDecision(): void
    {
        ++$this->decisionEventCount;
    }

    /** @return array<string, int|string> */
    public function boundaryObservation(string $probeId): array
    {
        return [
            'kind' => 'confirmation_boundary',
            'probe_id' => $probeId,
            'proposal_count' => $this->proposalCount,
            'token_issue_count' => $this->tokenIssueCount,
            'token_consume_count' => $this->tokenConsumeCount,
            'decision_event_count' => $this->decisionEventCount,
        ];
    }
}
