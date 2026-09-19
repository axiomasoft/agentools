<?php

declare(strict_types=1);

namespace AgenTools\Reference\Scenario;

use AgenTools\Confirmation\ConfirmationJournalSupport;
use AgenTools\Confirmation\ConfirmationProtocol;
use AgenTools\Confirmation\ConfirmationToken;
use AgenTools\Confirmation\GatePolicy;
use AgenTools\Confirmation\PolicyVerdict;
use AgenTools\Confirmation\Proposal;
use AgenTools\Confirmation\ProposalState;
use AgenTools\Error\ToolExecutionException;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Reference\Adapters\DeliveryCollector;
use AgenTools\Reference\Adapters\RegistryToolPort;
use AgenTools\Contracts\Clock;
use AgenTools\Reference\Support\JsonGuard;
use AgenTools\Reference\Support\SideEffectPolicy;
use AgenTools\Schema\Canonicalizer;
use AgenTools\Tool\DescriptorDigest;
use AgenTools\Tool\ToolDescriptor;

/** Protocol-layer two-phase execution for reference slice tools (no domain gate). */
final class SliceToolCoordinator
{
    private ?ConfirmationToken $pendingToken = null;

    private ?Proposal $lastProposal = null;

    private int $proposalSequence = 0;

    /** @var array<string, true> */
    private array $invalidatedTokens = [];

    public function __construct(
        private readonly RegistryToolPort $tools,
        private readonly ConfirmationProtocol $protocol,
        private readonly DeliveryCollector $delivery,
        private readonly ConfirmationMetrics $metrics,
        private readonly Canonicalizer $canonicalizer,
        private readonly Clock $clock,
        private readonly string $actionId,
        private readonly string $principalId,
        private readonly PermissionCommitAuthorizer $authorizer,
    ) {
    }

    /** @param array<string, mixed> $arguments */
    public function invoke(string $toolName, array $arguments, string $idempotencyKey, ?string $approval = null): ToolExecutionResult
    {
        $descriptor = $this->descriptorFor($toolName);
        $profile = $descriptor->actionProfile;
        $verdict = (new GatePolicy())->decide($profile);

        if (!SideEffectPolicy::requiresTwoPhase($profile->sideEffect)) {
            return $this->tools->call($toolName, $arguments, $idempotencyKey);
        }

        if ($verdict === PolicyVerdict::Deny) {
            return ToolExecutionResult::failure(new \AgenTools\Error\ToolError(
                'agentools.permission.denied',
                'permission',
                false,
                'The policy layer denied this mutation.',
            ));
        }

        $fingerprint = ArgumentsFingerprint::fromArguments($arguments, $this->canonicalizer);
        $digest = DescriptorDigest::for($descriptor, $this->canonicalizer);

        if ($approval === null && $this->pendingToken === null) {
            return $this->prepare($toolName, $arguments, $fingerprint, $digest);
        }

        if ($approval === 'edit') {
            return $this->reprepare($toolName, $arguments, $fingerprint, $digest);
        }

        if ($approval === 'reject') {
            return $this->rejectPending();
        }

        if ($approval !== 'approve') {
            return ToolExecutionResult::failure(new \AgenTools\Error\ToolError(
                'agentools.precondition.confirmation_rejected',
                'precondition',
                false,
                'The confirmation was not approved.',
            ));
        }

        return $this->commit($toolName, $arguments, $idempotencyKey, $fingerprint, $digest);
    }

    /** Surfaces SUPERSEDED when a superseded token is presented after edit. */
    public function rejectSupersededToken(string $tokenValue): ToolExecutionResult
    {
        if (!isset($this->invalidatedTokens[$tokenValue])) {
            return ToolExecutionResult::failure(new \AgenTools\Error\ToolError(
                'agentools.precondition.confirmation_required',
                'precondition',
                false,
                'The token is not superseded.',
            ));
        }

        return ToolExecutionResult::failure(new \AgenTools\Error\ToolError(
            'agentools.precondition.confirmation_token_spent',
            'precondition',
            false,
            'SUPERSEDED: the confirmation token is no longer valid.',
        ));
    }

    public function pendingTokenValue(): ?string
    {
        return $this->pendingToken?->value;
    }

    /** @param array<string, mixed> $arguments */
    private function prepare(
        string $toolName,
        array $arguments,
        ArgumentsFingerprint $fingerprint,
        DescriptorDigest $digest,
    ): ToolExecutionResult {
        $proposal = $this->buildProposal($toolName, $arguments, $fingerprint, $digest);

        $this->metrics->recordProposal();
        $this->metrics->recordConfirmationRequest();
        $this->delivery->deliver(['type' => 'preview', 'tool' => $toolName, 'preview' => $proposal->preview]);
        $this->delivery->deliver(['type' => 'confirmation_request', 'tool' => $toolName]);

        $token = $this->protocol->prepare($proposal, $this->clock->now());
        $this->pendingToken = $token;
        $this->lastProposal = $proposal;
        $this->metrics->recordTokenIssue();

        return ToolExecutionResult::success(['awaiting_confirmation' => true, 'proposal_id' => $proposal->proposalId]);
    }

    /** @param array<string, mixed> $arguments */
    private function reprepare(
        string $toolName,
        array $arguments,
        ArgumentsFingerprint $fingerprint,
        DescriptorDigest $digest,
    ): ToolExecutionResult {
        if ($this->pendingToken === null || $this->lastProposal === null) {
            return ToolExecutionResult::failure(new \AgenTools\Error\ToolError(
                'agentools.precondition.confirmation_required',
                'precondition',
                false,
                'Nothing is awaiting confirmation.',
            ));
        }

        if ($this->lastProposal->argsFingerprint->value === $fingerprint->value) {
            return ToolExecutionResult::failure(new \AgenTools\Error\ToolError(
                'agentools.precondition.confirmation_rejected',
                'precondition',
                false,
                'Edit requires changed arguments; mutating the proposal in place is forbidden.',
            ));
        }

        $original = $this->lastProposal;
        $this->invalidatePendingToken();
        $successor = $this->buildProposal($toolName, $arguments, $fingerprint, $digest);
        foreach (ConfirmationJournalSupport::editJournalPayloads($original, $successor) as $payload) {
            $this->delivery->deliver(['type' => 'proposal', 'payload' => $payload]);
        }

        $this->metrics->recordProposal();
        $this->metrics->recordConfirmationRequest();
        $this->delivery->deliver(['type' => 'preview', 'tool' => $toolName, 'preview' => $successor->preview]);
        $this->delivery->deliver(['type' => 'confirmation_request', 'tool' => $toolName]);

        $token = $this->protocol->prepare($successor, $this->clock->now());
        $this->pendingToken = $token;
        $this->lastProposal = $successor;
        $this->metrics->recordTokenIssue();

        return ToolExecutionResult::success(['awaiting_confirmation' => true, 'proposal_id' => $successor->proposalId]);
    }

    private function rejectPending(): ToolExecutionResult
    {
        if ($this->pendingToken === null) {
            return ToolExecutionResult::failure(new \AgenTools\Error\ToolError(
                'agentools.precondition.confirmation_required',
                'precondition',
                false,
                'Nothing is awaiting confirmation.',
            ));
        }

        $this->invalidatePendingToken();
        $this->metrics->recordDecision();
        $this->delivery->deliver(['type' => 'confirmation_resolved', 'decision' => 'reject']);

        return ToolExecutionResult::success(['confirmation_rejected' => true]);
    }

    private function invalidatePendingToken(): void
    {
        if ($this->pendingToken === null) {
            return;
        }

        $this->invalidatedTokens[$this->pendingToken->value] = true;
        $this->pendingToken = null;
    }

    /** @param array<string, mixed> $arguments */
    private function buildProposal(
        string $toolName,
        array $arguments,
        ArgumentsFingerprint $fingerprint,
        DescriptorDigest $digest,
    ): Proposal {
        ++$this->proposalSequence;
        $preview = [
            'summary' => 'Persist day report',
            'changes' => [['field' => 'body', 'value' => JsonGuard::string($arguments['body'] ?? null)]],
        ];

        return new Proposal(
            proposalId: 'prop-' . $this->actionId . '-' . $this->proposalSequence,
            proposalVersion: $this->proposalSequence,
            actionId: $this->actionId,
            toolName: $toolName,
            proposerId: $this->principalId,
            principal: $this->principalId,
            argsFingerprint: $fingerprint,
            descriptorDigest: $digest,
            expiresAt: $this->clock->now()->modify('+1 hour'),
            preview: $preview,
        );
    }

    /** @param array<string, mixed> $arguments */
    private function commit(
        string $toolName,
        array $arguments,
        string $idempotencyKey,
        ArgumentsFingerprint $fingerprint,
        DescriptorDigest $digest,
    ): ToolExecutionResult {
        $token = $this->pendingToken;
        if ($token === null) {
            return ToolExecutionResult::failure(new \AgenTools\Error\ToolError(
                'agentools.precondition.confirmation_required',
                'precondition',
                false,
                'A confirmation token is required before commit.',
            ));
        }

        if (isset($this->invalidatedTokens[$token->value])) {
            return ToolExecutionResult::failure(new \AgenTools\Error\ToolError(
                'agentools.precondition.confirmation_token_spent',
                'precondition',
                false,
                'SUPERSEDED: the confirmation token is no longer valid.',
            ));
        }

        try {
            $this->protocol->commit(
                $token->value,
                $fingerprint,
                $digest,
                $this->principalId,
                $this->clock->now(),
                $this->authorizer,
            );
        } catch (ToolExecutionException $exception) {
            return ToolExecutionResult::failure($exception->error);
        }

        $this->metrics->recordTokenConsume();
        $this->metrics->recordDecision();
        $this->delivery->deliver(['type' => 'confirmation_resolved', 'decision' => 'approve']);
        $this->pendingToken = null;

        $probe = $this->tools->call($toolName, $arguments, $idempotencyKey);
        $this->delivery->deliver(['type' => 'tool_boundary', 'tool' => $toolName, 'phase' => 'commit']);

        return $probe;
    }

    private function descriptorFor(string $toolName): ToolDescriptor
    {
        foreach ($this->tools->discover() as $descriptor) {
            if ($descriptor->name === $toolName) {
                return $descriptor;
            }
        }

        throw new \InvalidArgumentException(sprintf('Tool %s is not registered.', $toolName));
    }
}
