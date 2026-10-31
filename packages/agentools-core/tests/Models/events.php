<?php

declare(strict_types=1);

use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Models\ModelFixtures as F;
use AgenToolsPlatform\Core\Common\{Certainty, IntegrityConflict};
use AgenToolsPlatform\Core\Models\{EventKind, FinishReason, JsonObject, ModelEvent, ModelResponse, ModelUsage, StreamState, ToolProposal};

return [
    'models.stream-complete-identity' => static function (): void {
        $state = new StreamState(F::invocation());
        $state->accept(new ModelEvent(F::invocation(), 1, EventKind::Started));
        $state->accept(new ModelEvent(F::invocation(), 2, EventKind::TextDelta, 'hello'));
        $state->accept(new ModelEvent(F::invocation(), 3, EventKind::ProposalDelta, '{"id":', 'proposal-1', 'read_note'));
        $state->accept(new ModelEvent(F::invocation(), 4, EventKind::ProposalDelta, '1}', 'proposal-1', 'read_note'));
        $response = new ModelResponse(F::invocation(), F::deployment(), F::deployment(), 'provider-request', FinishReason::ToolProposal,
            proposals: [new ToolProposal(F::invocation(), 'proposal-1', 'read_note', new JsonObject(['id' => 1]))], usage: new ModelUsage(10, 3));
        $state->accept(new ModelEvent(F::invocation(), 5, EventKind::Terminal, response: $response));
        H::check(!$state->isUnknown() && $state->text() === 'hello' && $state->terminal() === $response);
        H::check($state->proposalDeltas()['proposal-1']['arguments'] === '{"id":1}');
    },
    'models.stream-missing-terminal-partial' => static function (): void {
        $state = new StreamState(F::invocation());
        $state->accept(new ModelEvent(F::invocation(), 1, EventKind::Started));
        $state->accept(new ModelEvent(F::invocation(), 2, EventKind::TextDelta, 'partial'));
        $state->accept(new ModelEvent(F::invocation(), 3, EventKind::Usage, usage: new ModelUsage(7)));
        H::check($state->isUnknown() && $state->text() === 'partial' && $state->terminal() === null);
        H::check($state->usage()->inputTokens === 7 && $state->usage()->outputTokens === null && $state->usage()->certainty() === Certainty::Unknown);
        $state->accept(new ModelEvent(F::invocation(), 4, EventKind::Terminal,
            response: new ModelResponse(F::invocation(), F::deployment(), null, null, FinishReason::Cancelled, partial: true)));
        H::check($state->usage()->inputTokens === 7 && $state->usage()->outputTokens === null);
    },
    'models.stream-duplicate-gap-terminal' => static function (): void {
        $state = new StreamState(F::invocation());
        H::rejects(static fn () => $state->accept(new ModelEvent(F::invocation(), 1, EventKind::TextDelta, 'wrong start')), IntegrityConflict::class);
        $state->accept(new ModelEvent(F::invocation(), 1, EventKind::Started));
        H::rejects(static fn () => $state->accept(new ModelEvent(F::invocation(), 3, EventKind::TextDelta, 'gap')), IntegrityConflict::class);
        H::rejects(static fn () => $state->accept(new ModelEvent(F::invocation(), 1, EventKind::Started)), IntegrityConflict::class);
        $state->accept(new ModelEvent(F::invocation(), 2, EventKind::Terminal,
            response: new ModelResponse(F::invocation(), F::deployment(), null, null, FinishReason::Cancelled, usage: new ModelUsage(7), partial: true)));
        H::check($state->terminal()?->partial === true && $state->usage()->outputTokens === null);
        H::rejects(static fn () => $state->accept(new ModelEvent(F::invocation(), 3, EventKind::Terminal,
            response: new ModelResponse(F::invocation(), F::deployment(), null, null, FinishReason::Completed))), IntegrityConflict::class);
    },
    'models.stream-proposal-conflict' => static function (): void {
        $state = new StreamState(F::invocation());
        $state->accept(new ModelEvent(F::invocation(), 1, EventKind::Started));
        $state->accept(new ModelEvent(F::invocation(), 2, EventKind::ProposalDelta, '{', 'same', 'read_note'));
        H::rejects(static fn () => $state->accept(new ModelEvent(F::invocation(), 3, EventKind::ProposalDelta, '}', 'same', 'write_note')), IntegrityConflict::class);
        H::rejects(static fn () => new ModelEvent(F::invocation(), 3, EventKind::Terminal, delta: 'untyped'), InvalidArgumentException::class);
        $state->accept(new ModelEvent(F::invocation(), 3, EventKind::ProposalDelta, '"id":1}', 'same', 'read_note'));
        H::rejects(static fn () => $state->accept(new ModelEvent(F::invocation(), 4, EventKind::Terminal,
            response: new ModelResponse(F::invocation(), F::deployment(), null, null, FinishReason::ToolProposal,
                proposals: [new ToolProposal(F::invocation(), 'same', 'read_note', new JsonObject(['id' => 2]))]))), IntegrityConflict::class);
    },
];
