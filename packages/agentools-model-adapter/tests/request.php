<?php

declare(strict_types=1);

use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\ModelAdapterTests\{Fixture as F, HttpScript};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, UtcInstant};
use AgenToolsPlatform\Core\Models\{CacheIntent, CacheMode, ContentKind, ContentPart, ContinuationRef, GenerationOptions, JsonObject, MessageRole, ModelMessage, ModelRequest, OutputSchema, ProviderOption, ToolDefinition, VisionDetail, VisionOption};

return [
 'adapter.request-wire' => static function (): void {
    $f = new F(); $http = F::jsonHttp(F::message()); $f->transport($http)->generate($f->request(structured: true), $f->deployment());
    H::check(count($http->requests) === 1); $request = $http->requests[0];
    H::check($request->url === 'https://api.anthropic.com/v1/messages' && $request->headers()['anthropic-version'] === '2026-10-21');
    $body = F::body($request);
    H::check(F::at($body, 'system', 0)->text === 'host system' && F::at($body, 'system', 1)->text === 'host policy');
    H::check(\AgenToolsPlatform\ModelAdapter\Anthropic\Fields::object($body, 'tool_choice')->type === 'auto' && F::at($body, 'tools', 0)->name === 'read_note');
    H::check($body->max_tokens === 1024 && \AgenToolsPlatform\ModelAdapter\Anthropic\Fields::object(\AgenToolsPlatform\ModelAdapter\Anthropic\Fields::object($body, 'output_config'), 'format')->type === 'json_schema');
    H::check(!isset($body->temperature) && !isset($body->top_p));
 },
 'adapter.optional-before-io' => static function (): void {
    $f = new F(); $base = $f->request(); $http = F::jsonHttp(F::message());
    $requests = [
      $f->request(options: [new VisionOption(VisionDetail::High)]),
      $f->request(options: [new ProviderOption('anthropic', 'internal_complete', new JsonObject(['callback' => 'execute']))]),
      new ModelRequest($base->invocationId, $base->messages, $base->contextSnapshotRef, $base->contextSnapshotHash, $base->credentialRef, $base->deadline, cache: new CacheIntent(CacheMode::Prefer, F::ref('cache'), 30)),
      new ModelRequest($base->invocationId, $base->messages, $base->contextSnapshotRef, $base->contextSnapshotHash, $base->credentialRef, $base->deadline, continuation: new ContinuationRef(F::ref('encrypted'), 'anthropic', 'account-A', 'eu', $base->deadline)),
    ];
    foreach ([ContentKind::Image, ContentKind::Audio, ContentKind::Ref] as $kind) {
      $requests[] = new ModelRequest($base->invocationId, [new ModelMessage(MessageRole::User, [ContentPart::reference(F::ref('media'), $kind, 'application/octet-stream')])], $base->contextSnapshotRef, $base->contextSnapshotHash, $base->credentialRef, $base->deadline);
    }
    foreach ($requests as $request) { H::rejects(static fn () => $f->transport($http)->generate($request, $f->deployment()), ContractViolation::class, ErrorCode::CapabilityUnavailable); }
    H::check($http->requests === [] && $f->keys->calls === 0);
 },
 'adapter.removed-generation-options' => static function (): void {
    $f = new F(); $base = $f->request(); $http = F::jsonHttp(F::message());
    foreach ([new GenerationOptions(1, 0.5), new GenerationOptions(1, topP: 0.5)] as $options) {
      $request = new ModelRequest($base->invocationId, $base->messages, $base->contextSnapshotRef, $base->contextSnapshotHash, $base->credentialRef, $base->deadline, generation: $options);
      H::rejects(static fn () => $f->transport($http)->stream($request, $f->deployment()), ContractViolation::class, ErrorCode::CapabilityUnavailable);
    }
    H::check($http->requests === []);
 },
 'adapter.current-credential-bindings' => static function (): void {
    foreach (['tenant' => 'beta', 'account' => 'account-B', 'provider' => 'other', 'region' => 'us', 'expires' => '2026-10-30T00:00:00Z'] as $field => $value) {
      $f = new F(); $http = F::jsonHttp(F::message()); $f->keys->{$field} = $value;
      $response = $f->transport($http)->generate($f->request(), $f->deployment());
      H::check($response->error?->code === ErrorCode::AccessDenied && $http->requests === []);
    }
    $f = new F(); $http = F::jsonHttp(F::message()); $driver = $f->transport($http);
    $driver->generate($f->request(), $f->deployment()); $f->keys->key = 'fixture-key-B'; $driver->generate($f->request(), $f->deployment());
    H::check($f->keys->calls === 2 && $http->requests[0]->headers()['x-api-key'] === 'fixture-key-A' && $http->requests[1]->headers()['x-api-key'] === 'fixture-key-B');
    H::check(!str_contains(print_r($http->requests[0], true), 'fixture-key-A'));
 },
 'adapter.revoke-before-send' => static function (): void {
    $f = new F(); $http = F::jsonHttp(F::message()); $f->guard->allowed = false;
    H::rejects(static fn () => $f->transport($http)->generate($f->request(), $f->deployment()), ContractViolation::class, ErrorCode::AccessDenied);
    $f->guard->allowed = true;
    $f->keys->onResolve = static function () use ($f): void { $f->guard->allowed = false; };
    $response = $f->transport($http)->generate($f->request(), $f->deployment());
    H::check($response->error?->code === ErrorCode::AccessDenied && $http->requests === []);
 },
 'adapter.cancel-deadline-fraction' => static function (): void {
    $f = new F(); $http = F::jsonHttp(F::message()); $original = $f->clock->now()->value;
    $f->clock->advanceMicroseconds(0); H::check($f->clock->now()->value === $original);
    $f->cancel->requested = true;
    H::rejects(static fn () => $f->transport($http)->generate($f->request(), $f->deployment()), ContractViolation::class, ErrorCode::Cancelled);
    $f->cancel->requested = false; $f->clock->advanceMicroseconds(60000000);
    H::rejects(static fn () => $f->transport($http)->generate($f->request(), $f->deployment()), ContractViolation::class, ErrorCode::DeadlineExceeded);
    H::check($http->requests === [] && $f->keys->calls === 0);
 },
 'adapter.schema-before-io' => static function (): void {
    $f = new F(); $base = $f->request(); $http = F::jsonHttp(F::message());
    foreach ([['type' => 'object', '$ref' => 'https://example.invalid/schema'], ['type' => 'object', '$filters' => ['external_callback']], ['type' => 'object', 'required' => 'not-an-array']] as $schema) {
      $request = new ModelRequest($base->invocationId, $base->messages, $base->contextSnapshotRef, $base->contextSnapshotHash, $base->credentialRef, $base->deadline, tools: [new ToolDefinition('read_note', '', new JsonObject($schema))]);
      H::rejects(static fn () => $f->transport($http)->generate($request, $f->deployment()), ContractViolation::class);
    }
    foreach ([['type' => 'number', 'minimum' => 0], ['type' => 'object', 'properties' => []], ['$ref' => '#', 'type' => 'object', 'additionalProperties' => false]] as $schema) {
      $request = new ModelRequest($base->invocationId, $base->messages, $base->contextSnapshotRef, $base->contextSnapshotHash, $base->credentialRef, $base->deadline, outputSchema: new OutputSchema('native', new JsonObject($schema)));
      H::rejects(static fn () => $f->transport($http)->generate($request, $f->deployment()), ContractViolation::class, ErrorCode::CapabilityUnavailable);
    }
    H::check($http->requests === [] && $f->keys->calls === 0);
 },
 'adapter.orphan-tool-result' => static function (): void {
    $f = new F(); $http = F::jsonHttp(F::message());
    $request = $f->request([new ModelMessage(MessageRole::Tool, [ContentPart::text('result')], 'missing')]);
    H::rejects(static fn () => $f->transport($http)->generate($request, $f->deployment()), ContractViolation::class, ErrorCode::InvalidInput);
    H::check($http->requests === []);
 },
];
