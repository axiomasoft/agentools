<?php

declare(strict_types=1);

use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Models\ModelFixtures as F;
use AgenToolsPlatform\Core\Common\{Certainty, ContractViolation, ErrorCode, PlatformError, RetryClass};
use AgenToolsPlatform\Core\Models\{CacheIntent, CacheMode, ContentKind, ContentPart, GenerationOptions, JsonObject, ModelMessage, MessageRole, ModelRequest, ModelResponse, ModelUsage, OutputSchema, FinishReason, ToolDefinition, ToolProposal};

return [
    'models.typed-request' => static function (): void {
        $base = F::request();
        $schema = new JsonObject(['type' => 'object', 'properties' => ['note' => ['type' => 'string']]]);
        $request = new ModelRequest($base->invocationId, [
            new ModelMessage(MessageRole::User, [ContentPart::text('read'), ContentPart::reference(F::ref('image'), ContentKind::Image, 'image/png'), ContentPart::reference(F::ref('audio'), ContentKind::Audio, 'audio/wav')]),
            new ModelMessage(MessageRole::Tool, [ContentPart::text('saved')], 'proposal-1'),
        ], $base->contextSnapshotRef, $base->contextSnapshotHash, $base->credentialRef, $base->deadline,
            system: 'host system', policy: 'host policy', tools: [new ToolDefinition('read_note', 'Read only', $schema)],
            outputSchema: new OutputSchema('note', $schema), cache: new CacheIntent(CacheMode::Require, F::ref('cache'), 30));
        H::check($request->system === 'host system' && $request->policy === 'host policy');
        H::check(count($request->requiredCapabilities(true)) >= 7);
        H::check($request->messages[1]->proposalRef === 'proposal-1');
        $proposal = new ToolProposal(F::invocation(), 'proposal-1', 'read_note', new JsonObject(['id' => 1]));
        $replay = new ModelMessage(MessageRole::Assistant, [], proposals: [$proposal]);
        H::check($replay->proposals[0] === $proposal);
        H::rejects(static fn () => new ModelMessage(MessageRole::User, [], proposals: [$proposal]), InvalidArgumentException::class);
    },
    'models.typed-values-reject-invalid' => static function (): void {
        H::rejects(static fn () => new GenerationOptions(0), InvalidArgumentException::class);
        H::rejects(static fn () => new OutputSchema('', new JsonObject(['type' => 'array'])), InvalidArgumentException::class);
        H::rejects(static fn () => new GenerationOptions(1, NAN), InvalidArgumentException::class);
        H::rejects(static fn () => new CacheIntent(CacheMode::Require), InvalidArgumentException::class);
        H::rejects(static fn () => new ModelMessage(MessageRole::Tool, [ContentPart::text('result')]), InvalidArgumentException::class);
        H::rejects(static fn () => new JsonObject(['callback' => static fn () => null]), InvalidArgumentException::class);
        H::rejects(static fn () => new ToolDefinition('run', '', new JsonObject(['type' => 'array'])), InvalidArgumentException::class);
        H::rejects(static fn () => ContentPart::reference(F::ref('image'), ContentKind::Image), InvalidArgumentException::class);
    },
    'models.tenant-bound-request' => static function (): void {
        $base = F::request();
        H::rejects(static fn () => new ModelRequest($base->invocationId, [new ModelMessage(MessageRole::User, [ContentPart::reference(F::ref('payload', 'beta'))])],
            $base->contextSnapshotRef, $base->contextSnapshotHash, $base->credentialRef, $base->deadline), ContractViolation::class, ErrorCode::AccessDenied);
    },
    'models.nullable-usage' => static function (): void {
        foreach (['models.partial-usage', 'models.observed-zero'] as $id) {
            $fixture = F::fixture($id);
            $input = $fixture['input_tokens']; $output = $fixture['output_tokens'];
            H::check(is_int($input) && ($output === null || is_int($output)));
            if (!is_int($input) || ($output !== null && !is_int($output))) { throw new RuntimeException('Bad shared usage'); }
            $usage = new ModelUsage($input, $output);
            H::check($usage->certainty()->value === $fixture['expected_certainty']);
            H::check($usage->outputTokens === $output);
        }
        H::rejects(static fn () => new ModelUsage(-1), InvalidArgumentException::class);
        H::check((new ModelUsage())->certainty() === Certainty::Unknown);
        foreach ([null, [1, 'two'], 'value', 3, true] as $value) {
            $structured = new \AgenToolsPlatform\Core\Models\StructuredValue($value);
            $response = new ModelResponse(F::invocation(), F::deployment(), null, null, FinishReason::Completed, structured: $structured);
            H::check($response->structured === $structured && $structured->value === $value);
        }
        H::rejects(static fn () => new \AgenToolsPlatform\Core\Models\StructuredValue(static fn () => null), InvalidArgumentException::class);
        H::check((new OutputSchema('list', new JsonObject(['type' => 'array', 'items' => ['type' => 'string']])))->schema->value['type'] === 'array');
    },
    'models.response-metadata-refusal' => static function (): void {
        $requested = F::deployment();
        $actual = new \AgenToolsPlatform\Core\Models\Deployment($requested->ref, 'provider', 'account-A', 'eu', 'model-actual', $requested->capabilities);
        $response = new ModelResponse(F::invocation(), $requested, $actual, 'request-1', FinishReason::Refusal,
            refusal: 'policy', usage: new ModelUsage(4), adapterVersion: 'test/1', partial: true, latencyMs: 2);
        H::check($response->actualDeployment?->model === 'model-actual' && $response->requestedDeployment->model === 'model-1');
        H::check($response->refusal === 'policy' && $response->usage->outputTokens === null && $response->partial);
        $response->assertFor(F::request(), $requested);
        H::rejects(static fn () => $response->assertFor(F::request(), $actual), \AgenToolsPlatform\Core\Common\IntegrityConflict::class);
        H::rejects(static fn () => new ModelResponse(F::invocation(), $requested, null, null, FinishReason::Refusal), InvalidArgumentException::class);
    },
    'models.normalized-errors' => static function (): void {
        $error = new PlatformError(ErrorCode::InvocationUnknown, RetryClass::StatusLookup, Certainty::Unknown, F::ref('trace'));
        $response = new ModelResponse(F::invocation(), F::deployment(), null, null, FinishReason::Unknown, error: $error, partial: true);
        H::check($response->actualDeployment === null && $response->error?->toArray()['public_message_key'] === 'agentools.platform.invocation_unknown');
        H::rejects(static fn () => new PlatformError(ErrorCode::InvocationUnknown, RetryClass::SafeSameKey, Certainty::Known, F::ref('trace')), InvalidArgumentException::class);
    },
    'models.proposal-only' => static function (): void {
        $proposal = new ToolProposal(F::invocation(), 'provider-tool-1', 'read_note', new JsonObject(['note_id' => 'n1']));
        $response = new ModelResponse(F::invocation(), F::deployment(), null, null, FinishReason::ToolProposal, proposals: [$proposal]);
        H::check($response->proposals[0]->providerProposalRef === 'provider-tool-1');
        H::check(!(new ReflectionClass($proposal))->hasMethod('execute'));
        H::rejects(static fn () => new ModelResponse(F::invocation(), F::deployment(), null, null, FinishReason::ToolProposal,
            proposals: [$proposal, new ToolProposal(F::invocation(), 'provider-tool-1', 'read_note', new JsonObject(['different' => 1]))]), \AgenToolsPlatform\Core\Common\IntegrityConflict::class);
    },
];
