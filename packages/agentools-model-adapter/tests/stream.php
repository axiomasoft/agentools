<?php

declare(strict_types=1);

use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\ModelAdapterTests\{Fixture as F, HttpScript};
use AgenToolsPlatform\Core\Common\{Certainty, ErrorCode};
use AgenToolsPlatform\Core\Models\{EventKind, FinishReason};
use AgenToolsPlatform\ModelAdapter\Http\{HttpEvent, TransportFailure};

return [
 'adapter.stream-chunks-and-tools' => static function (): void {
    foreach ([false, true] as $tool) {
      $f = new F(); $http = F::streamHttp(F::streamEvents($tool), 1, true); $out = $f->streamed($http);
      H::check($out['response']->finishReason === ($tool ? FinishReason::ToolProposal : FinishReason::Completed));
      H::check($out['response']->usage->inputTokens === 7 && $out['response']->usage->outputTokens === 3 && count($http->requests) === 1);
      H::check($out['response']->providerRequestId === 'req-stream');
      if ($tool) { H::check($out['response']->proposals[0]->providerProposalRef === 'toolu-stable' && $out['state']->proposalDeltas()['toolu-stable']['arguments'] === '{"note_id":"n1"}'); }
      else { H::check($out['state']->text() === 'partial'); }
    }
 },
 'V2ALL-SDK-FAULT' => static function (): void {
    foreach (['missing', 'duplicate', 'error', 'truncated-frame', 'malformed-after-prefix'] as $fault) {
      $f = new F(); $events = F::streamEvents();
      if ($fault === 'missing') { array_pop($events); }
      elseif ($fault === 'duplicate') { $events[] = (object) ['type' => 'message_stop']; }
      elseif ($fault === 'error') { $events = array_slice($events, 0, 3); $events[] = (object) ['type' => 'error', 'error' => (object) ['message' => 'PRIVATE']]; }
      $http = F::streamHttp($events);
      if ($fault === 'truncated-frame') {
        $wire = substr(implode('', array_map(F::frame(...), $events)), 0, -2);
        $http = new HttpScript(static fn () => [HttpEvent::headers(200), HttpEvent::body($wire)]);
      }
      if ($fault === 'malformed-after-prefix') {
        $wire = implode('', array_map(F::frame(...), array_slice(F::streamEvents(), 0, 3))) . "event: content_block_delta\ndata: {broken\n\n";
        $http = new HttpScript(static fn () => [HttpEvent::headers(200), HttpEvent::body($wire)]);
      }
      $out = $f->streamed($http);
      H::check($out['response']->finishReason === FinishReason::Unknown && $out['response']->partial && $out['response']->parts !== [] && $out['response']->parts[0]->text === 'partial');
      H::check($out['response']->usage->inputTokens === 7 && $out['response']->usage->outputTokens !== 0);
      H::check(count(array_filter($out['events'], static fn ($e) => $e->kind === EventKind::Terminal)) === 1);
    }
 },
 'adapter.stream-cancel-deadline-revoke' => static function (): void {
    foreach (['cancel', 'deadline', 'revoke'] as $fault) {
      $f = new F(); $prefix = implode('', array_map(F::frame(...), array_slice(F::streamEvents(), 0, 3)));
      $http = new HttpScript(static function () use ($f, $prefix, $fault): iterable {
        yield HttpEvent::headers(200); yield HttpEvent::body($prefix);
        if ($fault === 'cancel') { $f->cancel->requested = true; }
        elseif ($fault === 'deadline') { $f->clock->advanceMicroseconds(60000000); }
        else { $f->guard->allowed = false; }
        yield HttpEvent::body(F::frame((object) ['type' => 'message_stop']));
      });
      $out = $f->streamed($http);
      H::check($out['response']->finishReason === FinishReason::Unknown && $out['response']->error?->code === ErrorCode::InvocationUnknown);
      H::check($out['response']->usage->inputTokens === 7 && $out['response']->usage->outputTokens === null && $out['state']->text() === 'partial');
      H::check(count($http->requests) === 1);
    }
 },
 'adapter.stream-timeout' => static function (): void {
    $f = new F(); $prefix = implode('', array_map(F::frame(...), array_slice(F::streamEvents(), 0, 3)));
    $http = new HttpScript(static function () use ($prefix): iterable { yield HttpEvent::headers(200); yield HttpEvent::body($prefix); throw new TransportFailure(ErrorCode::ProviderTimeout, true); });
    $out = $f->streamed($http);
    H::check($out['response']->finishReason === FinishReason::Unknown && $out['response']->error?->code === ErrorCode::ProviderTimeout && $out['response']->usage->outputTokens === null);
 },
 'adapter.stream-cumulative-not-summed' => static function (): void {
    $f = new F(); $events = F::streamEvents();
    array_splice($events, 5, 0, [(object) ['type' => 'message_delta', 'delta' => (object) [], 'usage' => (object) ['output_tokens' => 5]]]);
    $out = $f->streamed(F::streamHttp($events));
    H::check($out['response']->usage->inputTokens === 7 && $out['response']->usage->outputTokens === 5);
 },
 'adapter.stream-block-and-id-conflicts' => static function (): void {
    foreach (['duplicate-block', 'missing-block-stop', 'wrong-delta', 'duplicate-id'] as $fault) {
      $f = new F(); $events = F::streamEvents(true);
      if ($fault === 'duplicate-block') { array_splice($events, 2, 0, [clone $events[1]]); }
      elseif ($fault === 'missing-block-stop') { array_splice($events, 3, 1); }
      elseif ($fault === 'wrong-delta') { \AgenToolsPlatform\ModelAdapter\Anthropic\Fields::object($events[2], 'delta')->type = 'text_delta'; }
      else { $copy = clone $events[1]; $copy->index = 1; array_splice($events, 4, 0, [$copy]); }
      $out = $f->streamed(F::streamHttp($events));
      H::check($out['response']->finishReason === FinishReason::Unknown && $out['response']->proposals === []);
    }
 },
 'adapter.stream-extension-and-empty-tool' => static function (): void {
    $f = new F(); $events = F::streamEvents();
    array_splice($events, 1, 0, [(object) ['type' => 'ping'], (object) ['type' => 'future_diagnostic', 'private' => 'not-memory']]);
    $out = $f->streamed(F::streamHttp($events));
    H::check($out['response']->finishReason === FinishReason::Completed && $out['response']->parts[0]->text === 'partial');
    $events = F::streamEvents(true); array_splice($events, 2, 1);
    $base = $f->request();
    $request = new \AgenToolsPlatform\Core\Models\ModelRequest($base->invocationId, $base->messages, $base->contextSnapshotRef, $base->contextSnapshotHash, $base->credentialRef, $base->deadline,
        tools: [new \AgenToolsPlatform\Core\Models\ToolDefinition('read_note', '', new \AgenToolsPlatform\Core\Models\JsonObject(['type' => 'object', 'properties' => [], 'additionalProperties' => false]))]);
    $state = new \AgenToolsPlatform\Core\Models\StreamState($request->invocationId);
    foreach ($f->transport(F::streamHttp($events))->stream($request, $f->deployment()) as $event) { $state->accept($event); }
    H::check($state->terminal()?->finishReason === FinishReason::ToolProposal && $state->proposalDeltas()['toolu-stable']['arguments'] === '{}');
 },
];
