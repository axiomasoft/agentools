<?php

declare(strict_types=1);

namespace AgenTools\Reference\Scenario;

use AgenTools\Confirmation\ConfirmationService;
use AgenTools\Confirmation\ConfirmationToken;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolExecutionException;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Reference\Adapters\DeliveryCollector;
use AgenTools\Reference\Adapters\RegistryToolPort;
use AgenTools\Schema\Canonicalizer;
use AgenTools\Reference\Support\JsonGuard;
use AgenTools\Reference\Support\SideEffectPolicy;
use AgenTools\Tool\ToolDescriptor;

/** Reference two-phase coordinator using the supported safe confirmation facade. */
final class SliceToolCoordinator
{
    private ?ConfirmationToken $pendingToken = null;

    private ?ArgumentsFingerprint $pendingFingerprint = null;

    /** @var array<string, true> */
    private array $invalidatedTokens = [];

    public function __construct(
        private readonly RegistryToolPort $tools,
        private readonly \AgenTools\Idempotency\IdempotencyCoordinator $idempotency,
        private readonly ConfirmationService $confirmation,
        private readonly DeliveryCollector $delivery,
        private readonly ConfirmationMetrics $metrics,
        private readonly Canonicalizer $canonicalizer,
        private readonly string $actionId,
    ) {
    }

    /** @param array<string, mixed> $arguments */
    public function invoke(string $toolName, array $arguments, string $idempotencyKey, ?string $approval = null): ToolExecutionResult
    {
        $descriptor = $this->descriptorFor($toolName);
        $verdict = (new \AgenTools\Confirmation\GatePolicy())->decide($descriptor->actionProfile);
        if (!SideEffectPolicy::requiresTwoPhase($descriptor->actionProfile->sideEffect)) {
            return $this->idempotency->call($toolName, $arguments, $idempotencyKey);
        }
        if ($verdict === \AgenTools\Confirmation\PolicyVerdict::Deny) {
            return ToolExecutionResult::failure(new ToolError('agentools.permission.denied', 'permission', false, 'The policy layer denied this mutation.'));
        }

        $fingerprint = ArgumentsFingerprint::fromArguments($arguments, $this->canonicalizer);
        if ($approval === null && $this->pendingToken === null) {
            return $this->prepare($toolName, $arguments, $fingerprint);
        }
        if ($approval === 'edit') {
            return $this->reprepare($toolName, $arguments, $fingerprint);
        }
        if ($approval === 'reject') {
            return $this->rejectPending();
        }
        if ($approval !== 'approve') {
            return ToolExecutionResult::failure(new ToolError('agentools.precondition.confirmation_rejected', 'precondition', false, 'The confirmation was not approved.'));
        }

        return $this->commit($toolName, $arguments, $idempotencyKey);
    }

    public function rejectSupersededToken(string $tokenValue): ToolExecutionResult
    {
        if (!isset($this->invalidatedTokens[$tokenValue])) {
            return ToolExecutionResult::failure(new ToolError('agentools.precondition.confirmation_required', 'precondition', false, 'The token is not superseded.'));
        }
        return ToolExecutionResult::failure(new ToolError('agentools.precondition.confirmation_token_spent', 'precondition', false, 'SUPERSEDED: the confirmation token is no longer valid.'));
    }

    public function pendingTokenValue(): ?string
    {
        return $this->pendingToken?->value;
    }

    /** @param array<string, mixed> $arguments */
    private function prepare(string $toolName, array $arguments, ArgumentsFingerprint $fingerprint): ToolExecutionResult
    {
        $preview = [
            'summary' => 'Persist day report',
            'changes' => [['field' => 'body', 'value' => JsonGuard::string($arguments['body'] ?? null)]],
        ];

        try {
            $token = $this->confirmation->prepare($this->actionId, $toolName, $arguments, $preview);
        } catch (ToolExecutionException $exception) {
            return ToolExecutionResult::failure($exception->error);
        }

        $this->pendingToken = $token;
        $this->pendingFingerprint = $fingerprint;
        $this->metrics->recordProposal();
        $this->metrics->recordConfirmationRequest();
        $this->metrics->recordTokenIssue();
        $this->delivery->deliver(['type' => 'preview', 'tool' => $toolName, 'preview' => $preview]);
        $this->delivery->deliver(['type' => 'confirmation_request', 'tool' => $toolName]);

        return ToolExecutionResult::success(['awaiting_confirmation' => true, 'proposal_id' => $token->proposalId]);
    }

    /** @param array<string, mixed> $arguments */
    private function reprepare(string $toolName, array $arguments, ArgumentsFingerprint $fingerprint): ToolExecutionResult
    {
        if ($this->pendingToken === null || $this->pendingFingerprint === null) {
            return ToolExecutionResult::failure(new ToolError('agentools.precondition.confirmation_required', 'precondition', false, 'Nothing is awaiting confirmation.'));
        }
        if ($this->pendingFingerprint->value === $fingerprint->value) {
            return ToolExecutionResult::failure(new ToolError('agentools.precondition.confirmation_rejected', 'precondition', false, 'Edit requires changed arguments.'));
        }

        $superseded = $this->pendingToken;
        $this->invalidatePendingToken();
        $this->delivery->deliver(['type' => 'proposal', 'state' => 'superseded', 'proposal_id' => $superseded->proposalId]);

        return $this->prepare($toolName, $arguments, $fingerprint);
    }

    private function rejectPending(): ToolExecutionResult
    {
        if ($this->pendingToken === null) {
            return ToolExecutionResult::failure(new ToolError('agentools.precondition.confirmation_required', 'precondition', false, 'Nothing is awaiting confirmation.'));
        }
        $this->invalidatePendingToken();
        $this->metrics->recordDecision();
        $this->delivery->deliver(['type' => 'confirmation_resolved', 'decision' => 'reject']);

        return ToolExecutionResult::success(['confirmation_rejected' => true]);
    }

    private function invalidatePendingToken(): void
    {
        if ($this->pendingToken !== null) {
            $this->invalidatedTokens[$this->pendingToken->value] = true;
        }
        $this->pendingToken = null;
        $this->pendingFingerprint = null;
    }

    /** @param array<string, mixed> $arguments */
    private function commit(string $toolName, array $arguments, string $idempotencyKey): ToolExecutionResult
    {
        $token = $this->pendingToken;
        if ($token === null) {
            return ToolExecutionResult::failure(new ToolError('agentools.precondition.confirmation_required', 'precondition', false, 'A confirmation token is required before commit.'));
        }
        if (isset($this->invalidatedTokens[$token->value])) {
            return ToolExecutionResult::failure(new ToolError('agentools.precondition.confirmation_token_spent', 'precondition', false, 'SUPERSEDED: the confirmation token is no longer valid.'));
        }

        try {
            $this->confirmation->commit($token->value, $arguments);
        } catch (ToolExecutionException $exception) {
            return ToolExecutionResult::failure($exception->error);
        }

        $this->metrics->recordTokenConsume();
        $this->metrics->recordDecision();
        $this->delivery->deliver(['type' => 'confirmation_resolved', 'decision' => 'approve']);
        $this->pendingToken = null;
        $this->pendingFingerprint = null;
        $probe = $this->idempotency->call($toolName, $arguments, $idempotencyKey);
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
