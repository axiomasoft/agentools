<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapterTests;

use AgenToolsPlatform\Core\Common\{Ref, TenantId, UtcInstant, InvocationId, PayloadHash, ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Models\{Capability, CapabilityEvidence, Deployment, Evidence, ModelMessage, ModelRequest, ModelResponse, ModelUsage, MessageRole, ContentPart, ToolDefinition, JsonObject, OutputSchema, Support, ModelEvent, EventKind, StreamState};
use AgenToolsPlatform\ModelAdapter\{CurrentGuard, CredentialProvider, Credential, IOControl};
use AgenToolsPlatform\ModelAdapter\Http\{HttpTransport, HttpRequest, HttpEvent};
use AgenToolsPlatform\ModelAdapter\Anthropic\Transport;
use AgenToolsPlatform\Testing\Models\{PreciseClock, CancellationSwitch};

final class Guard implements CurrentGuard
{
    public bool $allowed = true;
    public int $calls = 0;
    public function assertAllowed(ModelRequest $request, Deployment $deployment): void
    {
        ++$this->calls;
        if (!$this->allowed) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
}
final class Keys implements CredentialProvider
{
    public int $calls = 0;
    public string $key = 'fixture-key-A';
    public string $account = 'account-A';
    public string $tenant = 'alpha';
    public string $provider = 'anthropic';
    public string $region = 'eu';
    public string $expires = '2026-11-02T00:00:00Z';
    public ?\Closure $onResolve = null;
    public function resolve(Ref $credentialRef, Deployment $deployment): Credential
    {
        ++$this->calls;
        if ($this->onResolve !== null) { ($this->onResolve)(); }
        return new Credential(new Ref(new TenantId($this->tenant), $credentialRef->value), $this->provider, $this->account,
            $this->region, new UtcInstant($this->expires), $this->key);
    }
}
final class HttpScript implements HttpTransport
{
    /** @var list<HttpRequest> */ public array $requests = [];
    /** @var \Closure(HttpRequest, IOControl): iterable<HttpEvent> */ private \Closure $script;
    /** @param \Closure(HttpRequest, IOControl): iterable<HttpEvent> $script */
    public function __construct(\Closure $script) { $this->script = $script; }
    public function exchange(HttpRequest $request, IOControl $control): iterable
    {
        $this->requests[] = $request;
        return ($this->script)($request, $control);
    }
}
final class Fixture
{
    public PreciseClock $clock;
    public CancellationSwitch $cancel;
    public Guard $guard;
    public Keys $keys;
    public function __construct()
    {
        $this->clock = new PreciseClock(new UtcInstant('2026-10-31T12:00:00.123456Z'));
        $this->cancel = new CancellationSwitch(); $this->guard = new Guard(); $this->keys = new Keys();
    }
    public static function ref(string $id, string $tenant = 'alpha'): Ref { return new Ref(new TenantId($tenant), $id); }
    public static function invocation(string $id = 'invocation-1'): InvocationId { return new InvocationId(new TenantId('alpha'), $id); }
    public function deployment(): Deployment
    {
        $caps = [];
        foreach (Capability::cases() as $cap) {
            $caps[$cap->value] = new CapabilityEvidence(Support::Supported, Evidence::Documented,
                new UtcInstant('2026-10-30T00:00:00Z'), new UtcInstant('2026-11-01T00:00:00Z'), 'scripted-contract');
        }
        return new Deployment(self::ref('deployment-1'), 'anthropic', 'account-A', 'eu', 'requested-model', $caps);
    }
    public static function schema(): JsonObject
    {
        return new JsonObject(['type' => 'object', 'properties' => ['note_id' => ['type' => 'string']], 'required' => ['note_id'], 'additionalProperties' => false]);
    }
    /**
     * @param list<ModelMessage>|null $messages
     * @param list<\AgenToolsPlatform\Core\Models\ModelOption> $options
     */
    public function request(?array $messages = null, bool $tools = true, bool $structured = false, array $options = []): ModelRequest
    {
        return new ModelRequest(self::invocation(), $messages ?? [new ModelMessage(MessageRole::User, [ContentPart::text('Read note')])],
            self::ref('snapshot'), new PayloadHash('sha-256:' . str_repeat('a', 64)), self::ref('credential'),
            new UtcInstant('2026-10-31T12:01:00.123456Z'), $this->cancel, 'host system', 'host policy',
            $tools ? [new ToolDefinition('read_note', 'Read an authorized note', self::schema())] : [],
            $structured ? new OutputSchema('note', self::schema()) : null, options: $options);
    }
    public function transport(HttpTransport $http): Transport { return new Transport($http, $this->keys, $this->guard, $this->clock->now(...)); }
    /** @return \stdClass&object{id: string, type: string, role: string, model: string, content: list<\stdClass>, stop_reason: string|null} */
    public static function message(string $reason = 'end_turn', string $text = 'hello'): \stdClass
    {
        return (object) ['id' => 'msg-1', 'type' => 'message', 'role' => 'assistant', 'model' => 'actual-model',
            'content' => [(object) ['type' => 'text', 'text' => $text]], 'stop_reason' => $reason];
    }
    /** @return \stdClass&object{id: string, type: string, role: string, model: string, content: list<\stdClass>, stop_reason: string|null} */
    public static function proposal(): \stdClass
    {
        $raw = self::message('tool_use');
        $raw->content = [(object) ['type' => 'tool_use', 'id' => 'toolu-stable', 'name' => 'read_note', 'input' => (object) ['note_id' => 'n1']]];
        return $raw;
    }
    public static function jsonHttp(\stdClass $raw, int $status = 200): HttpScript
    {
        return new HttpScript(static fn () => [HttpEvent::headers($status, 'req-1'), HttpEvent::body(json_encode($raw, JSON_THROW_ON_ERROR))]);
    }
    public static function frame(\stdClass $event): string { return 'event: ' . \AgenToolsPlatform\ModelAdapter\Anthropic\Fields::text($event, 'type') . "\ndata: " . json_encode($event, JSON_THROW_ON_ERROR) . "\n\n"; }
    /** @return list<\stdClass> */
    public static function streamEvents(bool $tool = false): array
    {
        $message = self::message(); $message->content = []; $message->stop_reason = null;
        $message->usage = (object) ['input_tokens' => 7];
        $block = $tool ? (object) ['type' => 'tool_use', 'id' => 'toolu-stable', 'name' => 'read_note', 'input' => (object) []] : (object) ['type' => 'text', 'text' => ''];
        return [(object) ['type' => 'message_start', 'message' => $message],
            (object) ['type' => 'content_block_start', 'index' => 0, 'content_block' => $block],
            (object) ['type' => 'content_block_delta', 'index' => 0, 'delta' => $tool ? (object) ['type' => 'input_json_delta', 'partial_json' => '{"note_id":"n1"}'] : (object) ['type' => 'text_delta', 'text' => 'partial']],
            (object) ['type' => 'content_block_stop', 'index' => 0],
            (object) ['type' => 'message_delta', 'delta' => (object) ['stop_reason' => $tool ? 'tool_use' : 'end_turn'], 'usage' => (object) ['output_tokens' => 3]],
            (object) ['type' => 'message_stop']];
    }
    /** @param list<\stdClass> $events */
    public static function streamHttp(array $events, int $chunk = 17, bool $crlf = false): HttpScript
    {
        if ($chunk < 1) { throw new \InvalidArgumentException('Positive chunk size required'); }
        $wire = implode('', array_map(self::frame(...), $events));
        if ($crlf) { $wire = str_replace("\n", "\r\n", $wire); }
        return new HttpScript(static function () use ($wire, $chunk): iterable {
            yield HttpEvent::headers(200, 'req-stream');
            foreach (str_split($wire, $chunk) as $bytes) { yield HttpEvent::body($bytes); }
        });
    }
    public static function body(HttpRequest $request): \stdClass
    {
        $body = json_decode($request->body, false, flags: JSON_THROW_ON_ERROR);
        if (!$body instanceof \stdClass) { throw new \RuntimeException('Invalid wire object'); }
        return $body;
    }
    public static function at(\stdClass $object, string $field, int $index): \stdClass
    {
        $items = \AgenToolsPlatform\ModelAdapter\Anthropic\Fields::items($object, $field);
        $value = $items[$index] ?? null;
        if (!$value instanceof \stdClass) { throw new \RuntimeException('Missing wire item'); }
        return $value;
    }
    /** @return array{state: StreamState, response: ModelResponse, events: list<ModelEvent>} */
    public function streamed(HttpTransport $http): array
    {
        $state = new StreamState(self::invocation()); $events = [];
        foreach ($this->transport($http)->stream($this->request(), $this->deployment()) as $event) { $events[] = $event; $state->accept($event); }
        $response = $state->terminal();
        if ($response === null) { throw new \RuntimeException('Missing adapter terminal'); }
        return ['state' => $state, 'response' => $response, 'events' => $events];
    }
}
