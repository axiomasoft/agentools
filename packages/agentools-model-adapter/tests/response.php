<?php

declare(strict_types=1);

use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\ModelAdapterTests\{Fixture as F, HttpScript};
use AgenToolsPlatform\Core\Common\{Certainty, ErrorCode};
use AgenToolsPlatform\Core\Models\{FinishReason, JsonObject, ModelRequest, OutputSchema, StructuredValue};
use AgenToolsPlatform\ModelAdapter\Http\{HttpEvent, TransportFailure};

return [
 'V2ALL-SDK-POSITIVE' => static function (): void {
    $f = new F(); $raw = F::proposal(); $raw->usage = (object) ['input_tokens' => 7, 'output_tokens' => 3, 'cache_read_input_tokens' => 2, 'cache_creation_input_tokens' => 0];
    $http = F::jsonHttp($raw); $response = $f->transport($http)->generate($f->request(), $f->deployment());
    H::check($response->finishReason === FinishReason::ToolProposal && $response->proposals[0]->providerProposalRef === 'toolu-stable');
    H::check($response->proposals[0]->invocationId->value === 'invocation-1' && $response->proposals[0]->arguments->value === ['note_id' => 'n1']);
    H::check($response->requestedDeployment->model === 'requested-model' && $response->actualDeployment?->model === 'actual-model');
    H::check($response->providerRequestId === 'req-1' && $response->adapterVersion === 'anthropic-messages/2026-10-21+adapter.1');
    H::check($response->usage->inputTokens === 7 && $response->usage->outputTokens === 3 && $response->usage->cacheReadTokens === 2 && $response->usage->cacheWriteTokens === 0);
    H::check($response->latencyMs !== null && $response->latencyMs >= 0 && count($http->requests) === 1);
    $base = $f->request();
    $writeSchema = new JsonObject(['type' => 'object', 'properties' => ['note_id' => ['type' => 'string'], 'body' => ['type' => 'string']], 'required' => ['note_id', 'body'], 'additionalProperties' => false]);
    $writeRequest = new ModelRequest(F::invocation('write-invocation'), $base->messages, $base->contextSnapshotRef, $base->contextSnapshotHash, $base->credentialRef, $base->deadline,
      tools: [new \AgenToolsPlatform\Core\Models\ToolDefinition('write_note', 'Propose a confirmed write', $writeSchema)]);
    $writeRaw = F::proposal(); $writeRaw->content[0]->name = 'write_note'; $writeRaw->content[0]->input = (object) ['note_id' => 'n1', 'body' => 'updated'];
    $writeHttp = F::jsonHttp($writeRaw); $writeResponse = $f->transport($writeHttp)->generate($writeRequest, $f->deployment());
    H::check($writeResponse->finishReason === FinishReason::ToolProposal && $writeResponse->proposals[0]->tool === 'write_note');
    H::check($writeResponse->proposals[0]->arguments->value['body'] === 'updated' && $writeResponse->proposals[0]->invocationId->value === 'write-invocation' && count($writeHttp->requests) === 1);

 },
 'adapter.usage-null-zero' => static function (): void {
    $f = new F(); $raw = F::message();
    $response = $f->transport(F::jsonHttp($raw))->generate($f->request(), $f->deployment());
    H::check($response->usage->inputTokens === null && $response->usage->outputTokens === null && $response->usage->certainty() === Certainty::Unknown);
    $raw->usage = (object) ['input_tokens' => 0, 'output_tokens' => 0];
    $response = $f->transport(F::jsonHttp($raw))->generate($f->request(), $f->deployment());
    H::check($response->usage->inputTokens === 0 && $response->usage->outputTokens === 0 && $response->usage->certainty() === Certainty::Known);
 },
 'adapter.proposal-hash-conflict' => static function (): void {
    $f = new F(); $raw = F::proposal(); $raw->content[] = clone $raw->content[0];
    $response = $f->transport(F::jsonHttp($raw))->generate($f->request(), $f->deployment());
    H::check(count($response->proposals) === 1);
    $raw->content[1]->input = (object) ['note_id' => 'different'];
    $response = $f->transport(F::jsonHttp($raw))->generate($f->request(), $f->deployment());
    H::check($response->finishReason === FinishReason::Unknown && $response->proposals === [] && $response->error?->code === ErrorCode::IntegrityConflict);
    $base = $f->request();
    $schema = new JsonObject(['type' => 'object', 'properties' => ['note_id' => ['anyOf' => [['type' => 'object'], ['type' => 'array']]]], 'required' => ['note_id']]);
    $request = new ModelRequest($base->invocationId, $base->messages, $base->contextSnapshotRef, $base->contextSnapshotHash, $base->credentialRef, $base->deadline,
      tools: [new \AgenToolsPlatform\Core\Models\ToolDefinition('read_note', '', $schema)]);
    $raw = F::proposal(); $raw->content[0]->input = (object) ['note_id' => (object) []];
    $other = clone $raw->content[0]; $other->input = (object) ['note_id' => []]; $raw->content[] = $other;
    $response = $f->transport(F::jsonHttp($raw))->generate($request, $f->deployment());
    H::check($response->error?->code === ErrorCode::IntegrityConflict && $response->proposals === [], 'Object/list distinctions must survive proposal conflict checks');
 },
 'adapter.proposal-validation' => static function (): void {
    foreach (['unadvertised', 'wrong-arguments'] as $variant) {
      $f = new F(); $raw = F::proposal();
      if ($variant === 'unadvertised') { $raw->content[0]->name = 'write_money'; }
      else { $raw->content[0]->input = (object) ['note_id' => 123]; }
      $raw->usage = (object) ['input_tokens' => 7];
      $response = $f->transport(F::jsonHttp($raw))->generate($f->request(), $f->deployment());
      H::check($response->finishReason === FinishReason::Unknown && $response->usage->inputTokens === 7 && $response->proposals === []);
    }
    $f = new F(); $base = $f->request();
    $schema = new JsonObject(['type' => 'object', 'properties' => ['note_id' => ['type' => 'string', 'default' => 'fabricated']], 'required' => ['note_id'], 'additionalProperties' => false]);
    $request = new ModelRequest($base->invocationId, $base->messages, $base->contextSnapshotRef, $base->contextSnapshotHash, $base->credentialRef, $base->deadline,
      tools: [new \AgenToolsPlatform\Core\Models\ToolDefinition('read_note', '', $schema)]);
    $raw = F::proposal(); $raw->content[0]->input = (object) [];
    $response = $f->transport(F::jsonHttp($raw))->generate($request, $f->deployment());
    H::check($response->finishReason === FinishReason::Unknown && $response->proposals === [], 'Schema defaults must never invent provider arguments');
 },
 'adapter.structured-full-validation' => static function (): void {
    $f = new F();
    $response = $f->transport(F::jsonHttp(F::message(text: '{"note_id":"n1"}')))->generate($f->request(structured: true), $f->deployment());
    H::check($response->finishReason === FinishReason::Completed && $response->structured instanceof StructuredValue && $response->structured->value === ['note_id' => 'n1']);
    foreach (['{"note_id":123}', '{"note_id":"n1","extra":true}', '{}', 'not-json'] as $text) {
      $response = $f->transport(F::jsonHttp(F::message(text: $text)))->generate($f->request(structured: true), $f->deployment());
      H::check($response->finishReason === FinishReason::Unknown && $response->structured === null);
    }
    $base = $f->request();
    $schema = new JsonObject(['type' => 'array', 'items' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]]]);
    $request = new ModelRequest($base->invocationId, $base->messages, $base->contextSnapshotRef, $base->contextSnapshotHash, $base->credentialRef, $base->deadline, outputSchema: new OutputSchema('array', $schema));
    $response = $f->transport(F::jsonHttp(F::message(text: '["one",null]')))->generate($request, $f->deployment());
    H::check($response->structured instanceof StructuredValue && $response->structured->value === ['one', null]);
 },
 'adapter.refusal-truncation' => static function (): void {
    $f = new F();
    foreach (['refusal' => FinishReason::Refusal, 'max_tokens' => FinishReason::Length] as $wire => $expected) {
      $raw = F::message($wire, 'incomplete JSON'); $raw->usage = (object) ['input_tokens' => 7];
      $response = $f->transport(F::jsonHttp($raw))->generate($f->request(structured: true), $f->deployment());
      H::check($response->finishReason === $expected && $response->structured === null && $response->usage->outputTokens === null);
      H::check($expected !== FinishReason::Refusal || $response->refusal === 'incomplete JSON');
    }
 },
 'adapter.http-errors-redacted' => static function (): void {
    $f = new F();
    foreach ([400 => ErrorCode::InvalidInput, 401 => ErrorCode::Unauthenticated, 403 => ErrorCode::AccessDenied, 429 => ErrorCode::AdmissionDenied, 500 => ErrorCode::InvocationUnknown] as $status => $expected) {
      $raw = (object) ['error' => (object) ['message' => 'fixture-key-A PRIVATE_PROVIDER_DIAGNOSTIC']];
      $response = $f->transport(F::jsonHttp($raw, $status))->generate($f->request(), $f->deployment());
      H::check($response->error?->code === $expected && $response->usage->outputTokens === null);
      $public = json_encode($response->error?->toArray(), JSON_THROW_ON_ERROR);
      H::check(!str_contains($public, 'fixture-key-A') && !str_contains($public, 'PRIVATE_PROVIDER_DIAGNOSTIC'));
    }
 },
 'adapter.timeout-no-retry' => static function (): void {
    $f = new F();
    $http = new HttpScript(static function (): iterable { throw new TransportFailure(ErrorCode::ProviderTimeout, true); });
    $response = $f->transport($http)->generate($f->request(), $f->deployment());
    H::check($response->finishReason === FinishReason::Unknown && $response->error?->code === ErrorCode::ProviderTimeout && $response->usage->outputTokens === null && count($http->requests) === 1);
 },
 'adapter.unknown-protocol-result' => static function (): void {
    $f = new F(); $raw = F::message('new_future_reason'); $raw->usage = (object) ['input_tokens' => 7];
    $response = $f->transport(F::jsonHttp($raw))->generate($f->request(), $f->deployment());
    H::check($response->finishReason === FinishReason::Unknown && $response->error?->certainty === Certainty::Unknown && $response->parts[0]->text === 'hello' && $response->usage->inputTokens === 7);
    $raw = F::message(); $raw->model = '';
    $response = $f->transport(F::jsonHttp($raw))->generate($f->request(), $f->deployment());
    H::check($response->finishReason === FinishReason::Unknown && $response->actualDeployment === null);
 },
];
