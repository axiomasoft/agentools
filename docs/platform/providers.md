# Provider fallback and opt-in probes

Fallback planning and attribution live in `Core\Models\Routing`; Runtime remains
the only Invocation/Action execution loop. A provider/SDK callback creates proposals,
never an effect. A replacement Invocation preserves the pinned bundle, goal, host
working-state ref, business-operation ref and accepted Action refs.

Before disclosure, `FallbackRouter` may select another permitted, qualified route.
The old undisclosed buffer is discarded. Policy/data/region/capability/current-owner
checks precede cost. There is no emergency escape to a prohibited cheap provider.
Open the new stable Invocation through the existing journal and obtain its resource
admission; keep the same host business operation and accepted Actions.

After disclosure, the old output is interrupted. Continuation needs explicit host
permission and a new attributed output segment. `OutputBoundary` binds ordered model
events to one Invocation/deployment/exact catalog model revision and returns chunks
with segment, invocation, deployment, requested/actual model and byte offset. Late
packets, changed source, changed context and forged segment reuse are refused. It
never concatenates two models into a single answer or submits accepted Actions again.

Returning a chunk counts conservatively as exposure, not destination acknowledgement
or cognitive understanding. This boundary is in-memory: the host must durably record
disclosure/delivery intent before allowing replacement IO and restore that exposure
after restart. It does not replace existing delivery receipts, Runtime fencing or
current authorization/disclosure guards. Alias models must be resolved to the exact
qualified catalog revision; a reported actual-model mismatch interrupts output.

An unknown effect blocks continuation. `EffectLookup` reads the same owner operation
key, verifies the accepted Action and requires evidence for a known outcome. Merely
changing the model or calling `interrupt(..., null)` cannot clear uncertainty. A known
outcome still grants no permission to retry an Action; normal owner idempotency and
confirmation/current guards remain authoritative for any genuinely new proposal.

Each fallback Invocation has a separate normalized resource fact. Unknown quantities
remain NULL; provider attempts do not become zero spend. Corrections replace immutable
prior versions, and SDK secondary observers are telemetry-only. Business entitlement
is separate from resource usage and anti-abuse counters. Cache misses rebuild/finalize
and use normal admission; cache hits do not grant rights or establish savings.

`conformance/platform/provider-fallback` provides offline stream/fallback/cost and SDK
wire fixtures. Generate, stream, structured output, tools, cancel, errors and nullable
usage are exercised with the existing adapter and scripted HTTP. Continuation, cache
and attachments are refused before IO for its current declared adapter contour. These
fixtures qualify no live model, provider brand, price or educational/domain behavior.

Run the offline inventory without provider IO:

```sh
php conformance/platform/provider-fallback/probe.php --offline
```

Live probes require an explicit trusted, operator-owned PHP bootstrap:

```sh
php conformance/platform/provider-fallback/probe.php --live --host-bootstrap=/trusted/test-account-bootstrap.php
```

That bootstrap returns `harness` (`Testing\Providers\OptInHarness`), typed `request`,
typed `deployment`, and optional `mode=generate|stream`. It injects the actual guarded
transport and a real host `ProbeBudget`; there is no default unlimited owner. Only
operator CLI code may choose the bootstrap, never a model payload or web parameter.

`ProbePolicy` must explicitly enable synthetic data, bind a test credential and exact
deployment/account/region/model price scope, approve `ProbePolicy::payloadHash(request)`,
and bound data/output bytes, calls, time and cumulative microcurrency reservation.
Current price upper bounds need currency, pricing version, source evidence and expiry;
unknown prices are refused. The byte-token bound is conservative, not an exact model
tokenizer or a guarantee about undisclosed provider billing. The authoritative test
account must enforce its real spend/admission limits and record actual/unknown usage.

The default live scope excludes persistent cache, continuation and external payload
refs, whose storage rights/content sizes need a separate accepted policy. Supported
wire fixtures do not implicitly authorize these live capabilities. `ProbeBudget`
must atomically persist/dedup the Invocation+payload intent before IO and reject
previously attempted/unknown keys, including after process restart. The harness also
refuses duplicate attempts and performs no automatic resend or release after loss.
Its report deliberately leaves provider qualification unset and educational
qualification insufficient until the owning evaluation/rollout process supplies
the required exact-tuple evidence. No live calls are authorized by running offline CI.

P9.5 prepares `review-handoff.json`, `review-inputs.tar` and `review-limitations.json`
for the coordinator's single read-only review. Historical native P9.1–P9.4 PASS,
their exact candidates, complete original/correction manifests and logs remain
separate from current P9.5 evidence. The packet lists drift rather than rewriting
old manifests. The reviewer must read full required contents through native stream
tools and identify those actual read results; archive hashes or an index are not
proof of consumption. The executor launches no reviewer, commits nothing and stops
after P9. One correction maximum; after it, deterministic checks only, no re-review.
