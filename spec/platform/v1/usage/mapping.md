# Usage normalization and owner boundary

UsageEvent v1 is an additive technical fact. The PHP platform core and Dart
`platform_usage.dart` entrypoint implement the same normalization and replacement
fold; the existing dated SDK and Common\UsageVersion transport facts retain their
contracts. No metering, quota, wallet or business-debit ledger is introduced.

Identity is `(tenant_ref, invocation_id, usage_version)`. event_id and canonical
content remain stable across delivery retries. Same identity or event_id with a
different canonical hash raises USAGE_PAYLOAD_CONFLICT; the delivery is quarantined
by its owner and contributes no additional units. Corrections have a new event ID,
a larger version and supersedes_event_id referencing the prior active event. The
fold retains immutable history and selects one replacement per invocation. A delta
owner API must be converted to a full replacement by its adapter before append.
Missing predecessors/branches refuse the fold; reordering complete history is safe.

Every invocation has one host-pinned canonical producer: transport instrumentation
or SDK hook. Other observers are telemetry-only and cannot return a canonical event.
The consumer also rejects producer changes within an invocation correction chain.
Raw accounting values are read only through a declared field whitelist; raw prompts,
secrets or arbitrary provider metadata never enter the normalized event.

## Declared adapter mapping

The shipped mapping is **scripted-subset/v1**, a synthetic contract, with no claim
about any real provider. A real adapter must supply its own verified mapping/ref.

| Provider field | Normalized fact | Relationship |
| --- | --- | --- |
| input_total and cached_subset | input_uncached_tokens = input_total - cached_subset | cached_subset is included in input_total; missing either leaves uncached null |
| cached_subset | input_cache_read_tokens | subset of total input, never added to total input again |
| cache_write | input_cache_write_tokens | subset of uncached input in this mapping; not an additional input total |
| output | output_tokens | reported output |
| reasoning | reasoning_tokens | subset of output, never added to output again |
| audio_input / audio_output | audio_input_units / audio_output_units | exact decimal strings with explicit audio_unit |
| amount | provider_amount | exact reported decimal/currency, absent remains null |

The separate mapping test demonstrates independently billable cache-write/reasoning
dimensions. There is deliberately no universal sum over all unit fields. Absent
provider facts remain null independently; observed zero remains zero. Unknown lost
replies retain proven request_count=1 with actual quantities/amount null. Cancelled
streams keep observed partial quantities without inventing final usage. Estimates
are separately labeled `kind=estimate`; pricing_version is a reference, not a price.

## Admission and lifecycle

BudgetPort remains the existing host-owner port: admit(request), status(request),
settle(reservation,usage_event), release(reservation,proven_not_sent_reason), and
renew(reservation,deadline). status carries the same operation key **and content
hash**, so the owner can detect ADMISSION_PAYLOAD_CONFLICT. AdmissionScope adds the
strict canonical request builder with tenant/actor/subject, purpose/capability/task,
turn/run/invocation/business/bundle/deployment refs, nullable estimated units,
labeled estimated amount/currency, pricing version, deadline, profile/context hashes and policy versions.

BudgetBoundary consults current host entitlement/permissions/emergency policy and
the owner. Unknown, unavailable, denied and expired never become admitted; there
is no local unlimited fallback. Same admission key is retried through owner status
before admit. A profile pin cannot freeze entitlement. The owner must enforce key
hash binding atomically; the platform does not duplicate its reservation ledger.

ReservationCheckpoint is a technical projection of pending/active/settling/settled/
releasing/released/unknown, not authoritative money state. Unknown settle/release
replies preserve unknown and require owner status using the same operation key.
Release requires trusted not_sent evidence and owner-confirmed release. Worker TTL,
stream cancellation and missing usage do not prove not_sent. Settlement requires
known outcome/final usage bound to the same invocation/business operation.

## Persistence, fallback and consumers

UsagePort is the append/outbox boundary: its production adapter must append the
fact in the same local transaction as the invocation result. Existing PostgreSQL
InvocationRepository already commits technical usage_versions/outbox atomically;
this slice does not replace that journal or install an external export adapter.
The canonical envelope is an additive contract for that owning adapter/export
work. It must reuse the existing usage identity, not instrument a second producer.
Dispatcher retries change delivery attempt only. Actual durable export/ack and
external-owner integration remain separate evidence from this contract tier.

Fallback gets a new Invocation/resource fact and explicit owner resource admission,
while retaining business_operation_ref. Two request_count facts represent two
resource attempts, not two business debits. ControLLM consumes canonical facts and
replacement corrections; Tariffler owns entitlement; money settlement remains the
host money owner. This module invokes no provider, Action or business charge.
Mandatory accounting outage must fail closed or follow an explicit bounded durable
export policy. Optional telemetry outage cannot grant accounting permission.
