# Durable usage export

Install `Usage\Schema::install` explicitly after provisioning the native platform
schema. It installs checksum-pinned additive accounting tables, including the
technical reservation projection. Deployment owns installation; no worker runs DDL.

Construct a tenant-bound `Repository`, `Recorder` with an explicit `ProviderMapping`,
and `Budget\Reservations` with real host `BudgetPort`, current `BudgetGuard` and clock.
`Laravel\Usage\Accounting` opts the existing managed composition into these adapters.
Both journal and recorder must use the identical `Connection`. It is one runtime
advance: reservation precedes committed outbound intent; response, legacy technical
usage and normalized fact/export intent commit together. There is one export
producer; the old wake outbox usage signal is suppressed on this configured path.
Other SDK observers use the core normalizer's telemetry-only branch.

`append` is tenant/event-ID and tenant/invocation/version idempotent. A changed
canonical hash commits a hash-only quarantine and fences further delivery of that
invocation. Corrections append a replacement fact linked to the immutable predecessor.
The dispatcher exports predecessors first. Metering consumers fold replacements;
they must not add preliminary and final versions as separate calls.

| Source of truth | Local mapping | External owner obligation |
| --- | --- | --- |
| Invocation + explicit mapping | UsageEvent with nullable quantities, stable event/version/hash | ControLLM/host `MeteringPort`: status and idempotent export of exactly that fact |
| Native response transaction | accounting_usage + accounting_export, no prompt/response bytes | Retry changes attempt/epoch, never event identity |
| input total and cached subset | Declared `inputIncludesCacheRead`, subtract only with both fields known | No generic sum of overlapping quantities |
| ModelUsage fields | Host supplies mapping to `inputTokens`, `cacheReadTokens`, `cacheWriteTokens`, `outputTokens` | ModelUsage alone does not prove model revision, prices, audio or reasoning quantities |
| Explicit provider revision/amount | NULL when unavailable | No fabricated actual amount or inferred pricing |
| Owner reservation | BudgetPort admission and opaque refs | Tariffler/host owns money/entitlement; no local debit/quota ledger |
| Lost settle/release/renew ACK | Immutable operation payload, request hash, original admission key | `StatusPort` supplies operation-bound applied/not_applied proof; admission allow is insufficient |

The ports describe required adapter behavior, not existing ControLLM/Tariffler SDKs.
An owner without lookup/idempotency cannot be advertised as supporting safe replay.
Its unresolved operation stays pending/manual. Real owner integration and live
provider qualification are outside this contract harness.

The explicit backlog policy counts unexported facts plus outstanding reservations
without a fact. Tenant-serialized admission reserves capacity before owner IO.
At capacity/oldest-export-age limit new admissions fail closed. Facts of previously
admitted work and corrections are always retained, even during an outage; this is
an admission bound, not a destructive storage quota. Administrative cleanup or
manual-resolution policy must preserve unknown exposure. See the disabled example
in `fixtures/platform/v1/admission/backlog-policy.json`.

Only normalized closed UsageEvent fields enter export storage. Owner exception
messages and payloads never enter technical errors/quarantine/worker output; ACKs
are stored as hashes. Host-issued opaque refs must themselves be free of secrets.
Long-lived Laravel workers create a fresh tenant dispatcher per job and execute
connection reset before and in `finally` after each job. Do not retain tenant
repositories or use request anti-abuse counters for resource billing.

Focused tests live in `tests/Usage/{ports,policy,durable,recovery,concurrency}.php`.
The registered `usage-reservations` test/pg-test partitions require exact case sets,
input hashes, isolation proof and real SIGKILL/competing-connection observations.
