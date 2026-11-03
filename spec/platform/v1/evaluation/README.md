# Internal technical evaluation v1

This additive harness lives in the development-only `agentools-testing` package.
It creates no EvalKit package, service, agent loop or protocol status. The dated
ActionStatus and existing compatibility, conformance, owner and dependency gates
remain authoritative. It never executes a tool/model proposal or calls a provider.

## Inputs, outputs and identity

`case.schema.json` pins a local input stimulus by byte hash, expected hard
invariants, deterministic scorer configuration, profile/task, synthetic evidence
access policy and explicit currency/call/time budget. All seven hard invariants
are required: tenant isolation, audience isolation, permitted tools/effects,
absence of duplicates, unknown distinct from success, provenance, and a child
safety baseline independent of tariff. A case cannot remove one to obtain PASS.
The built-in scorer's implementation hash is part of its config and therefore
part of the case/suite hash. `input_fixture` contains the actual stimulus; fixture
observations are synthetic examples of the scorer input, not executed runtime.

`suite.schema.json` has a nonempty exact case list with content pins, required
scorers and local versioned corpus refs. Missing files/scorers/cases, duplicate
IDs, stale stimulus/case/corpus/scorer hashes and empty suites fail closed. Local
references cannot escape the repository through absolute paths, `..` or symlinks.
Integration refs preserve the original runner names, partitions and exact cases.
Historical upstream receipt refs/hashes are provenance only; they confer no fresh
PASS, and no plan source or historical receipt is loaded by the product runner.

`run.schema.json` pins deployment/model revision, profile/task, suite, bundle,
prompt, skills, tool schema and material corpus hashes, seed (including null),
start time, budget, tenant/audience and a canonical evidence kind. `run_hash`
seals every field except itself. Existing conformance receipt links include their
exact required/executed cases, hashes, tier, failures and skips. The in-process
`ImmutableRegistry` accepts identical tenant-scoped replay and rejects a differing
hash for the same tenant/kind/identity (and suite version); a new suite version is
a new immutable release. A host must supply durable storage across
process restarts. It is not a production certificate store.

Hashes use `sha-256:` plus 64 lowercase hexadecimal digits. File pins hash exact
UTF-8 bytes. Object pins hash UTF-8 JSON with sorted keys, compact separators,
unescaped Unicode and no nonfinite numbers (`harness.canonical`). Array order is
significant. This is the harness's versioned canonicalization, not an RFC 8785
claim. A suite hash includes case content pins and ordered integration manifests.
Schema/config changes require a versioned input regeneration; old runs are never
silently rebound. `samples.py` rebuilds the examples and pins from their sources.

`observation.schema.json` is a trusted producer input, with explicit hard-invariant
facts, nullable metrics/usage and redacted, hashed evidence refs. The harness does
not infer runtime safety from model text. Tenant, audience and ref allowlist checks
occur before evidence is retained in results. Fixtures allow synthetic data only;
child/personal data requires a separate owner policy and is outside this corpus.
The caller must authorize reads under current owner permissions/emergency guards;
an old evidence policy or certificate never grants present access.

`result.schema.json` contains deterministic scorer outputs, config and input/run
hashes, metrics, evidence refs, limits and a sealed result hash. False hard facts
or exceeded budgets fail. Missing/null hard facts, unknown cost/calls/time or
missing evidence produce `insufficient_evidence`; unexecuted observations produce
`not_run`. None of these is success or zero. Domain hooks are declared as
`kind=domain`; until the owner supplies its reviewed implementation, gold and
baseline, they return null value and `insufficient_evidence`. No dynamic code is
loaded from a scorer name/config and no LLM judge replaces a domain fact.

Metrics are quality, grounding, cost, latency, privacy, duplicate and unknown.
They remain null when unmeasured. There are no invented universal quality/domain
thresholds. Explicit per-run resource budgets and binary technical invariants are
distinct from pedagogical quality. A technical-only qualification can succeed
while the result/domain assessment remains insufficient.

`qualification.schema.json` uses an exact deployment/model/profile/task/suite/
bundle/prompt/skill/tool/corpus tuple, suite versions, sealed result refs, decision,
timestamps and rollout policy ref. Any required component mismatch rejects a new
assessment and makes a prior certificate inapplicable. Missing linked runtime
receipts or absent required scorers yield insufficient evidence. A run combines
only one profile/task; aggregate teacher/support manifests execute separate
journeys and are not a cross-profile certificate. Result seals, case/config hashes
and exact scorer/case sets are checked before qualification. P12.2 owns durable
qualification snapshots, expiry, current guards, canary assignment and rollback;
this slice performs no routing or active-session mutation.

## CLI and evidence

```
python3 tools/platform_gate.py test --suite evaluation-harness --receipt /tmp/evaluation.json
python3 tools/platform_gate.py e2e --scenario technical-golden --receipt /tmp/golden.json
python3 tools/platform_gate.py e2e --scenario evaluation-faults --receipt /tmp/faults.json
```

The first command runs the exact named scorer/schema/negative cases and reports
`contract-scripted` evidence. Its teacher/support examples test evaluation logic;
they do not claim runtime execution. The second serially reuses real kernel +
isolated PostgreSQL knowledge/read/second-host journeys: trusted text, generic
teacher material/context reads and an independent support host, deterministic
scripted ModelTransport, durable response/UsageEvent and recovery. The third
executes existing provider fallback and voice fault suites without weakening their
validators. No integration runner is replaced with a source inspection or an
imported receipt. The scenario manifests contain the exact original case sets.
Each original suite report and dependency hash remains in the aggregate receipt.

Missing runner/dependency/JSON-schema library, empty/extra/duplicate/missing cases,
required skip, failure or invalid evidence returns nonzero. Runtime scenarios
require the repository's proven isolated PostgreSQL environment and retain its
allow-marker/owner/database proof. Offline scripted owners are not real remote
host/provider support; live provider execution remains opt-in in its owning scope.
No live mode, provider spending, classic mutation testing or rollout is added here.

`receipt.schema.json` defines the normalized evaluation evidence envelope:
exact command/exit, UTC timestamps, source revision/dirty digest, input hashes,
suite/run pins, exact required/executed case set, canonical evidence kind,
environment/isolation, redacted artifact hashes and limitations. The existing gate
receipt keeps its backwards-compatible envelope and nests `evaluation_receipt`
with canonical evidence_kind, suite and invocation hashes. The invocation seal
binds the actual CLI command, start time, source identity and suite dependency
manifest. It is separate from the model/profile `Run` seal used for qualification.
`contract-scripted` examples and focused-test baseline do
not establish a runtime/live qualification. Aliases such as `fixture` are not
accepted in new receipts. A fake/technical tier cannot claim production; even a
product-pilot tier alone does not authorize an owner rollout.

Focused tests are `tests/Evaluation/test_harness.py` and the gate registry test.
The baseline report measures evaluator execution time and case count, with zero
provider calls/spend and null domain metrics. It measures no model latency,
grounding quality, acoustic quality, child safety expertise or educational score.
