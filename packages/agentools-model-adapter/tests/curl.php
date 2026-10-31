<?php

declare(strict_types=1);

use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\ModelAdapterTests\Fixture as F;
use AgenToolsPlatform\ModelAdapter\IOControl;
use AgenToolsPlatform\ModelAdapter\Http\{CurlTransport, HttpRequest, TransportFailure};
use AgenToolsPlatform\Core\Common\ErrorCode;

$base = getenv('AGENTOOLS_MODEL_TEST_TLS_URL'); $ca = getenv('AGENTOOLS_MODEL_TEST_CA');
if (!is_string($base) || $base === '' || !is_string($ca) || !is_file($ca)) { throw new RuntimeException('Required isolated loopback TLS server is missing'); }

return [
 'adapter.curl-streaming' => static function () use ($base, $ca): void {
    $f = new F(); $control = new IOControl($f->request(), $f->deployment(), $f->guard, $f->clock->now(...));
    $client = new CurlTransport($ca); $chunks = []; $status = null; $requestId = null; $first = null; $started = hrtime(true);
    foreach ($client->exchange(new HttpRequest($base . '/stream', ['content-type' => 'application/json'], '{}'), $control) as $event) {
      if ($event->status !== null) { $status = $event->status; $requestId = $event->requestId; }
      if ($event->bytes !== null) { $first ??= hrtime(true); $chunks[] = $event->bytes; }
    }
    H::check($status === 200 && $requestId === 'req-loopback' && count($chunks) >= 2 && implode('', $chunks) === 'firstlast');
    H::check($first !== null && $first - $started < 1000000000);
    $http = new \AgenToolsPlatform\ModelAdapterTests\HttpScript(static function (HttpRequest $request, IOControl $control) use ($base, $client): iterable {
      H::check($request->url === \AgenToolsPlatform\ModelAdapter\Anthropic\Transport::ENDPOINT);
      return $client->exchange(new HttpRequest($base . '/messages', $request->headers(), $request->body), $control);
    });
    $response = $f->transport($http)->generate($f->request(), $f->deployment());
    H::check($response->finishReason === \AgenToolsPlatform\Core\Models\FinishReason::Completed && $response->parts[0]->text === 'loopback' && $response->actualDeployment?->model === 'loopback-model');
    $stream = $f->streamed($http);
    H::check($stream['response']->finishReason === \AgenToolsPlatform\Core\Models\FinishReason::Completed && $stream['response']->usage->inputTokens === 4 && $stream['response']->usage->outputTokens === 2 && count($http->requests) === 2);
 },
 'adapter.curl-cancel-deadline-guard' => static function () use ($base, $ca): void {
    foreach (['cancel' => ErrorCode::Cancelled, 'deadline' => ErrorCode::DeadlineExceeded, 'revoke' => ErrorCode::AccessDenied] as $fault => $reason) {
      $f = new F(); $control = new IOControl($f->request(), $f->deployment(), $f->guard, $f->clock->now(...));
      $client = new CurlTransport($ca); $sawBytes = false; $started = hrtime(true); $caught = null;
      try {
        foreach ($client->exchange(new HttpRequest($base . '/stall', [], '{}'), $control) as $event) {
          if ($event->bytes !== null) {
            $sawBytes = true;
            if ($fault === 'cancel') { $f->cancel->requested = true; }
            elseif ($fault === 'deadline') { $f->clock->advanceMicroseconds(60000000); }
            else { $f->guard->allowed = false; }
          }
        }
      } catch (TransportFailure $error) { $caught = $error; }
      H::check($sawBytes && $caught?->reason === $reason && $caught->mayHaveSent === true);
      H::check(hrtime(true) - $started < 1000000000, 'Cancellation must stop before the stalled response finishes');
    }
 },
 'adapter.curl-tls-and-redirect' => static function () use ($base, $ca): void {
    H::rejects(static fn () => \AgenToolsPlatform\ModelAdapter\Http\CurlRuntime::assertCancellable(['features' => 0]), TransportFailure::class);
    \AgenToolsPlatform\ModelAdapter\Http\CurlRuntime::assertCancellable(curl_version());
    $f = new F(); $control = new IOControl($f->request(), $f->deployment(), $f->guard, $f->clock->now(...));
    H::rejects(static fn () => iterator_to_array((new CurlTransport())->exchange(new HttpRequest($base . '/stream', [], '{}'), $control)), TransportFailure::class);
    $statuses = [];
    foreach ((new CurlTransport($ca))->exchange(new HttpRequest($base . '/redirect', [], '{}'), $control) as $event) { if ($event->status !== null) { $statuses[] = $event->status; } }
    H::check($statuses === [302], 'Redirect must not make a second authenticated request');
    H::rejects(static fn () => new HttpRequest('http://localhost/cleartext', [], '{}'), InvalidArgumentException::class);
    H::rejects(static fn () => new HttpRequest($base, ['x-api-key' => "bad\r\nInjected: value"], '{}'), InvalidArgumentException::class);
 },
];
