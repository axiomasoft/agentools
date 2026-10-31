<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter\Anthropic;

use AgenToolsPlatform\Core\Common\{Certainty, ContractViolation, ErrorCode, PlatformError, Ref, RetryClass, UtcInstant};
use AgenToolsPlatform\Core\Models\{ContentPart, Deployment, EventKind, FinishReason, ModelEvent, ModelRequest, ModelResponse, ModelTransport, TransportPreflight};
use AgenToolsPlatform\ModelAdapter\{CredentialProvider, CurrentGuard, IOControl, SystemClock};
use AgenToolsPlatform\ModelAdapter\Http\{HttpRequest, HttpTransport, TransportFailure};

/** Exactly one Messages request. Domain dispatch and the durable loop stay in Runtime. */
final readonly class Transport implements ModelTransport
{
    public const VERSION = 'anthropic-messages/2026-10-21+adapter.1';
    public const API_VERSION = '2026-10-21';
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    public const LOOP_OWNER = 'AgenTools Runtime';
    private \Closure $now;
    private RequestEncoder $encoder;
    private ResponseDecoder $decoder;

    /** @param (\Closure(): UtcInstant)|null $now */
    public function __construct(private HttpTransport $http, private CredentialProvider $credentials,
        private CurrentGuard $guard, ?\Closure $now = null)
    {
        $this->now = $now ?? (new SystemClock())->now(...);
        $schemas = new SchemaRules();
        $this->encoder = new RequestEncoder($schemas); $this->decoder = new ResponseDecoder($schemas);
    }
    public function generate(ModelRequest $request, Deployment $deployment): ModelResponse
    {
        $body = $this->prepare($request, $deployment, false);
        foreach ($this->exchange($request, $deployment, $body, false) as $event) {
            if ($event->response !== null) { return $event->response; }
        }
        throw new \LogicException('Transport did not produce a terminal');
    }
    public function stream(ModelRequest $request, Deployment $deployment): iterable
    {
        return $this->exchange($request, $deployment, $this->prepare($request, $deployment, true), true);
    }
    private function prepare(ModelRequest $request, Deployment $deployment, bool $stream): string
    {
        TransportPreflight::check($request, $deployment, ($this->now)(), $stream);
        $body = $this->encoder->encode($request, $deployment, $stream);
        $this->guard->assertAllowed($request, $deployment);
        return $body;
    }
    /** @return iterable<ModelEvent> */
    private function exchange(ModelRequest $request, Deployment $deployment, string $body, bool $stream): iterable
    {
        $state = new StreamMachine($request, $deployment, $this->decoder);
        $sse = new SseDecoder();
        $control = new IOControl($request, $deployment, $this->guard, $this->now, $stream);
        $status = null; $requestId = null; $buffer = ''; $received = 0; $entered = false;
        $started = hrtime(true);
        yield new ModelEvent($request->invocationId, 1, EventKind::Started);
        try {
            $control->check();
            $credential = $this->credentials->resolve($request->credentialRef, $deployment);
            $credential->assertBound($request->credentialRef, $deployment, $control->now());
            $control->check();
            $httpRequest = new HttpRequest(self::ENDPOINT, ['anthropic-version' => self::API_VERSION,
                'content-type' => 'application/json', 'accept' => $stream ? 'text/event-stream' : 'application/json',
                'x-api-key' => $credential->apiKey()], $body);
            $entered = true;
            foreach ($this->http->exchange($httpRequest, $control) as $event) {
                $control->check();
                if ($event->status !== null) {
                    if ($status !== null) { throw new ProtocolFailure('duplicate_http_headers'); }
                    $status = $event->status;
                    $requestId = $event->requestId;
                    if ($requestId !== null && preg_match('/^[A-Za-z0-9_.:-]{1,256}$/D', $requestId) !== 1) { throw new ProtocolFailure('invalid_request_id'); }
                    continue;
                }
                if ($status === null || $event->bytes === null) { throw new ProtocolFailure('body_without_headers'); }
                $received += strlen($event->bytes);
                if ($received > 8388608) { throw new ProtocolFailure('response_limit'); }
                if ($status !== 200) { continue; }
                if ($stream) {
                    foreach ($sse->feed($event->bytes) as $wireEvent) {
                        foreach ($state->accept($wireEvent) as $normalized) { yield $normalized; }
                    }
                } else { $buffer .= $event->bytes; }
            }
            $control->check();
            if ($status === null) { throw new ProtocolFailure('missing_http_headers'); }
            if ($status !== 200) {
                $response = $this->httpError($status, $state, $request, $deployment, $requestId);
            } elseif ($stream) {
                $sse->finish();
                $response = $state->finish($requestId);
            } else {
                $raw = json_decode($buffer, false, 64, JSON_THROW_ON_ERROR);
                if (!$raw instanceof \stdClass) { throw new ProtocolFailure('invalid_json_message'); }
                $state->observe($raw);
                $response = $this->decoder->decode($raw, $request, $deployment, $requestId);
            }
        } catch (\Throwable $error) {
            $sent = $error instanceof TransportFailure ? $error->mayHaveSent : $entered;
            $reason = $error instanceof TransportFailure ? $error->reason : ($error instanceof ContractViolation ? $error->errorCode : ErrorCode::DeploymentUnavailable);
            if ($error instanceof \AgenToolsPlatform\Core\Common\IntegrityConflict) { $reason = ErrorCode::IntegrityConflict; }
            $response = $this->error($state, $request, $deployment, $requestId, $reason, $sent);
        }
        $response = new ModelResponse($response->invocationId, $response->requestedDeployment, $response->actualDeployment,
            $response->providerRequestId, $response->finishReason, $response->parts, $response->proposals, $response->structured,
            $response->refusal, $response->usage, $response->adapterVersion, $response->error, $response->partial,
            $response->continuation, max(0, intdiv(hrtime(true) - $started, 1000000)));
        $response->assertFor($request, $deployment);
        yield $state->event(EventKind::Terminal, response: $response);
    }
    private function httpError(int $status, StreamMachine $state, ModelRequest $request, Deployment $deployment, ?string $requestId): ModelResponse
    {
        $reason = match ($status) {
            400, 413, 422 => ErrorCode::InvalidInput, 401 => ErrorCode::Unauthenticated,
            403 => ErrorCode::AccessDenied, 429 => ErrorCode::AdmissionDenied, 408 => ErrorCode::ProviderTimeout,
            default => $status >= 500 ? ErrorCode::InvocationUnknown : ErrorCode::DeploymentUnavailable,
        };
        return $this->error($state, $request, $deployment, $requestId, $reason, in_array($reason, [ErrorCode::ProviderTimeout, ErrorCode::InvocationUnknown], true));
    }
    private function error(StreamMachine $state, ModelRequest $request, Deployment $deployment, ?string $requestId, ErrorCode $reason, bool $sent): ModelResponse
    {
        $unknown = $sent || $reason->requiresUnknown() || $reason === ErrorCode::ProviderTimeout;
        if ($unknown && !in_array($reason, [ErrorCode::ProviderTimeout, ErrorCode::IntegrityConflict], true)) { $reason = ErrorCode::InvocationUnknown; }
        $allowed = $reason->allowedRetries();
        $retry = $unknown ? ($reason === ErrorCode::IntegrityConflict ? RetryClass::Manual : RetryClass::StatusLookup) : (in_array(RetryClass::Never, $allowed, true) ? RetryClass::Never
            : (in_array(RetryClass::AfterPolicyRefresh, $allowed, true) ? RetryClass::AfterPolicyRefresh : RetryClass::StatusLookup));
        $trace = new Ref($request->invocationId->tenant, 'model:' . hash('sha256', $request->invocationId->value));
        $error = new PlatformError($reason, $retry, $unknown ? Certainty::Unknown : Certainty::Known, $trace);
        return new ModelResponse($request->invocationId, $deployment, $state->model === null ? null : $this->decoder->actual($deployment, $state->model),
            $requestId, $unknown ? FinishReason::Unknown : ($reason === ErrorCode::Cancelled ? FinishReason::Cancelled : FinishReason::Error),
            parts: $state->text === '' ? [] : [ContentPart::text($state->text)], usage: $state->usage,
            adapterVersion: self::VERSION, error: $error, partial: $unknown || $state->text !== '');
    }
}