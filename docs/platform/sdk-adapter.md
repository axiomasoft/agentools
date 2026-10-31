# Selected model adapter

Private `agentools/platform-model-adapter` owns `AgenToolsPlatform\ModelAdapter\`.
The selected driver is `Anthropic\Transport`, implementing the existing core
`ModelTransport`. PHP >=8.3, native ext-curl/ext-json, and this package's exact
Composer lock form its target stack. There is no Laravel or AI SDK dependency;
SDK package/version remain null. The selected JSON Schema validator is
`opis/json-schema` 2.6.0 at source commit 8458763e0dd0b6baa310e04f1829fc73da4e8c8a, recorded in this package's
own lock. The lock is compared with installed production package versions and
references by contract tests, including a corrupted-pin negative example.

The driver pins `POST https://api.anthropic.com/v1/messages` and
`anthropic-version: 2026-10-21`. That version removes temperature/top_p/top_k;
non-null typed temperature/topP and all unrecognized provider options are rejected
before credential lookup or HTTP. See [official API versioning](https://platform.claude.com/docs/en/api/versioning)
and [Messages](https://platform.claude.com/docs/en/api/messages).

## ADR: a single owner of the model loop

P1.2/P1.4 choice C is retained: AgenTools Runtime owns the durable loop. One
`generate` or `stream` invocation performs exactly one HTTP exchange. The driver
has no tool handler registry, effect callback, Action dispatch, approval store,
retry loop or fallback exchange. Native cURL callbacks only move HTTP bytes and
check IO controls. Host guard and credential ports remain mandatory constructor
inputs. Existing LlmPort/ScenarioRunner and dated wire enums are unchanged.

Runtime persists proposal-to-Action mapping and committed intent, applies current
permissions/confirmation/fences, then explicitly starts another invocation with
assistant proposals and tool results. It must reconcile an unknown effect before
retrying. The driver preserves the provider's tool_use IDs and replays them as
user tool_result.tool_use_id; it never turns them into execution grants.
[Provider tool handling](https://platform.claude.com/docs/en/agents-and-tools/tool-use/implement-tool-use)
is protocol evidence, not proof of production Runtime recovery.

SDK-001 exercises two explicitly requested model exchanges with a test-only
committed SQLite mapping/intent between them. An independent connection observes
the committed intent before the harness callback. Reopening the harness reuses a
saved result, rejects changed hashes, and refuses an ambiguous effect retry. This
proves the adapter boundary with a fixture, not AgenTools PostgreSQL Actions or a
production durable loop. `durable_loop_proven` and `live_qualified` remain false.

## Capability matrix

| Capability | Initial driver support | Local evidence |
| --- | --- | --- |
| Text, separate host system/policy instructions | supported | Encoded as separate system blocks; request contract tests |
| Client tool proposals, stable IDs, explicit result replay | supported | SDK-001, proposal/schema/conflict and streaming tests |
| Native structured output | supported within documented schema restrictions | Local schema and result validation, refusal/truncation tests |
| Incremental stream, terminal/error detection | supported | Fragmented scripted SSE and real loopback TLS through CurlTransport |
| Live cancellation/deadline/current guards | supported at polling boundaries | Before-send, during-read, native stalled-response cancellation tests |
| Current tenant/provider/account/region credentials | supported through required trusted host port | Binding, expiry, rotation and revoke-before-send tests |
| Nullable input/output/cache usage | supported as observations | Missing differs from zero; cumulative updates replace counts and retain omitted facts |
| Explicit prompt cache, audio, image/reference attachments | unsupported before IO | Typed capability rejection, no silent downgrade |
| Opaque provider continuation, provider options, server tools | unsupported before IO | Typed capability rejection; no portable provider state |
| Deployment availability/legal/data/quality qualification | unknown | Host/catalog evidence and live qualification remain external |

Support here describes the driver implementation and local contracts. It does not
certify any real account/model/region. Capability evidence must separately permit
the request. Unknown, unverified, future or expired evidence is denied before IO.
Projected tool names use the initial driver wire alias alphabet `[A-Za-z0-9_-]`, at most 64 characters; incompatible names are rejected before IO.
The host CurrentGuard rechecks permission/admission/administrative/emergency state
before credentials are resolved, immediately before sending, and while IO proceeds.
No caller-provided boolean supplies that authority. Host policy decides draining
and in-flight restrictions; the driver offers no emergency escape route.

## JSON Schema boundary

Tools use locally validated JSON Schema 2020-12. Native output schemas additionally
follow the selected provider's supported grammar features. Objects require explicit
`additionalProperties:false`. Local refs are supported; native recursive refs,
allOf containing refs, external refs, unsupported schema features and strict=false
are rejected before IO. Pattern grammars and unsupported native constraints are
not silently stripped. The supported native keywords are listed in SchemaRules;
non-supported format/constraint options return capability_unavailable.
See [provider structured output restrictions](https://platform.claude.com/docs/en/build-with-claude/structured-outputs).

Structural schema validation uses bundled 2020-12 metaschemas; validation of actual
arguments/results uses Opis. Default insertion is disabled for both operations:
validation must never invent a provider field. The metaschemas come from the
`jsonschema-specifications` 2025.9.1 distribution, with wheel/resource hashes and
its license in resources/json-schema. This is a vendored data input, not a Python
runtime dependency. External schema resolution and Opis executable extension
keywords are disabled. There is no network schema lookup.

The core JSON wrappers expose PHP associative arrays for object members. The
adapter validates the original JSON object/array types before projecting them to
those existing wrappers. Explicit structured JSON null uses StructuredValue(null),
while an absent result remains null. Refusal/truncation bypass completed-output
validation and never fabricate a structured value or dispatchable partial proposal.

## IO, errors and recovery

CurlTransport verifies TLS and hostnames, disables redirects and automatic retries,
and bounds response bytes, connection time, total time and polling waits. It requires native asynchronous DNS support before IO so resolver waits cannot bypass cancellation checks. The
HTTPS endpoint is code-owned; user/model input cannot select a URL. Tests inject a
loopback routing HttpTransport only in the test contour, keeping TLS verification
and production driver encoding/decoding intact. Native sockets, malformed framing,
partial data, cancellation and denied guards are all exercised without a provider.

Credentials are resolved again per exchange, checked against their trusted ref and
deployment binding/expiry, and included only in HTTP headers. Debug output redacts
credentials/headers/body; normalized public errors never contain raw provider or
cURL diagnostics. Production hosts own credential refresh, workspace binding and current revoke checks. Deployment.account must be the complete host account/project/workspace scope key; credential lookup must not collapse distinct workspace scopes. No provider credential qualification call is performed by this driver.

Streaming returns ordered typed events. The adapter waits for EOF before emitting
its single normalized terminal so duplicate/late provider terminals cannot become
a successful result. Missing terminal, malformed frames, unknown output shape or
post-send interruption yields invocation_unknown (provider_timeout for timeout),
retaining display fragments and observed nullable usage. Unknown transport errors permit
status lookup, never automatic retry; differing proposal content under one provider
reference is integrity_conflict with manual resolution. Conflict checks compare
original JSON types before projecting to core PHP values. HTTP refusal and known pre-send cancellation
remain distinct. Actual observed model and provider request ID remain separate
from requested deployment; neither is guessed when absent. Latency measures local
exchange elapsed time, not provider inference duration.

[Official streaming semantics](https://platform.claude.com/docs/en/build-with-claude/streaming)
and [public cURL multi API](https://www.php.net/manual/en/function.curl-multi-exec.php)
are recorded source evidence; offline tests do not establish provider support.

## Verification and packaging

The exact 31-case partition is registered as sdk-adapter, with model-adapter and
sdk-contract aliases pointing to that same partition. It includes request/response/
stream/ownership cases, native isolated TLS cases and a clean locked --no-dev
install. Missing runners/dependencies, wrong case sets, failures and skips fail.
The complete partition is conductor-owned; focused file runs use
`php packages/agentools-model-adapter/tests/run.php request` (also response, stream,
ownership), `python3 packages/agentools-model-adapter/tests/loopback.py`, and
`python3 packages/agentools-model-adapter/tests/no_dev.py`.

The no-dev test installs into a temporary directory from composer.lock, rather
than copying vendor. It checks production types/resources and rejects Testing,
Reference, fixture and framework autoload. The package requires no testing library
in production. Model testing helpers stay in platform-testing. This evidence is
contract-scripted/unit and local loopback IO only, not live-provider, production,
canonical ControLLM metering or deployment qualification. P3.5 F2 audit/reset and
P2.4 ManualClock fractional-second findings remain unresolved and are not used as
proof by this adapter.
