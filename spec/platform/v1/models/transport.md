# Additive ModelTransport v1

This is a private platform API, separate from dated 2026-09 wire enums. The PHP
port is `AgenToolsPlatform\Core\Models\ModelTransport`:
`generate(ModelRequest, Deployment): ModelResponse` and
`stream(ModelRequest, Deployment): iterable<ModelEvent>`. Dart mirrors the values
in `package:agentools/platform_models.dart` with `Future<ModelResponse>` and
`Stream<ModelEvent>`; it never casts asynchronous work into legacy `respond`.
This slice proves local contracts only. It installs no driver, calls no provider,
and proves neither a durable loop nor deployment qualification.

Requests carry tenant-scoped invocation, context snapshot ref/hash, trusted
credential ref, typed messages/parts, separate host system and policy boundaries,
projected tool schemas, proposal-only mode, output schema, generation limits,
deadline/live cancellation, cache intent and typed options. Tool results name the
provider proposal ref; assistant messages can replay typed originating proposals. Content refs must belong to the invocation tenant. No tool
callback is accepted. Ref resolution, current authorization, emergency state and
credential disclosure remain host/driver responsibilities immediately before IO.
The required read/write vertical includes text, tools/proposals and structured
output; the selected P4.2 driver must implement those rather than evade them.

Each deployment binds provider/account/region/model to a tenant-scoped ref and
capability evidence. Unknown, unsupported, unverified, future or expired evidence
rejects required capability before IO. This rejection is `capability_unavailable`
(the CM-028 label UNSUPPORTED_CAPABILITY is a semantic alias, not a new wire enum).
Each adapter must also reject unsupported exact option types/names/values before
IO even if the general capability is supported. Cache Prefer expresses intent,
not permission to silently ignore an unavailable cache capability.

Output schemas retain provider-specific restrictions for adapter validation before IO. StructuredValue represents any JSON value, including explicit null distinct from absent structured output; JsonObject remains the object form.

Responses preserve requested and independently observed actual deployment (null
when unobserved), provider request id, display parts, structured output, stable
provider proposal refs scoped by tenant/invocation, finish reason/refusal, nullable
usage/cache facts, adapter version, optional latency and normalized PlatformError.
A partial or missing result stays partial/unknown. Zero usage is only observed
zero. Errors carry code, retry class, certainty, public message key and scoped
trace/optional diagnostic refs. They contain no raw provider response or secrets;
use the existing platform errors and dated translation table, never new dated
ActionStatus or ToolErrorCode members.

Events have consecutive sequence numbers starting at 1, one started event,
text/proposal deltas (including provider proposal id and tool name), versioned
usage observations by event sequence, and exactly one terminal ModelResponse.
Deltas are display/proposal fragments, not dispatchable Actions. Duplicate/gapped
sequence, duplicate/late terminal and changed tool identity reject as integrity
conflicts. End of input without terminal is explicitly unknown and retains partial
text, proposal fragments and usage; it cannot manufacture completed/failed/zero.
Cancellation before send rejects locally; cancellation during IO retains observed
facts and requires the driver to distinguish a known cancelled terminal from an
unknown invocation. StreamState.usage retains earlier observed facts when a later update or terminal omits them; terminal is the reported response, not an invented usage total. There are no retries or effect callbacks in these ports.

ContinuationRef is only a handle to externally encrypted opaque state with
provider/account/region/tenant binding and expiry. Mismatch or expiry denies reuse
before IO. It contains no raw continuation bytes and is not portable memory or
chain-of-thought. Storage encryption and cleanup are external ports, not claims
made by constructing this value. Deadlines and TTL compare microseconds using a
host-provided UTC instant, independent of the unresolved testing ManualClock bug.

PHP bridges require an explicit host mapper because real LlmPort arrays have no
universal envelope. ModelTransportLlmPort invokes generate exactly once and maps
back to existing arrays/exceptions. LegacyLlmPortTransport invokes the actual
respond method once after preflight and mapper validation; synchronous LlmPort
cannot provide streaming. A mapper must retain exceptions and reject rich outcomes
that its legacy array API cannot represent. No implicit method, built-in prompt
format, tool callback, second model loop or ScenarioRunner model integration exists.
