# P2 design — safe confirmation and authorization boundary

**Date:** 2026-09-19  
**Tree anchor:** `3a0fc4de2c57e2d350ffa9ef252eb2162a7579bc` (`main`)  
**Evidence class:** `RAG:—` unless a row points to `P2-design-rag.md`.

## Design question

How can Agentools make the supported PHP/Dart confirmation API impossible to weaken per call,
bind approval to the exact authoritative action and policy context, preserve frozen `2026-08`, and
leave durable token/event/package decisions with P3/P5/P6/P7?

## Repository observations

| Observation | Evidence | Design consequence |
|:--|:--|:--|
| PHP and Dart `commit` accept current descriptor, principal, time, authorizer, optional proposer and `dualControl=false` from the caller. | both `confirmation_protocol` implementations | A safe facade must omit every one of those per-call inputs; null checks inside the old method do not close the class of bypasses. |
| Dual control rejects only `proposer == principal` when the caller enables it; `null`, empty/foreign proposer and `dualControl=false` avoid the check. | implementations and focused tests | Policy resolves separation/quorum and authoritative proposer; caller never selects whether the rule applies. |
| `DecisionAudit::assertAllowsCommit` checks decision and three argument hashes, but does not compare its proposal ID, token or actor with the parsed token/current actor. | PHP/Dart `DecisionAudit` and protocol | Approval evidence needs an explicit full binding tuple and an exact comparison before authorization. |
| Signed token covers action, args, descriptor, expiry, principal, proposal ID/version and tool; it does not cover tenant, proposer, policy identity/revision or approval requirement. | PHP/Dart token payload keys | P2 defines logical binding and authoritative lookup; P3 decides token/durable representation, so P2 must not silently extend wire format. |
| Frozen proposal schema has optional policy `{effect, source_scope, hardened}` and no policy ID/revision/quorum; SDK `Proposal` does not carry that policy object. | `spec/revisions/2026-08/schema/confirmation.json`; SDK classes | New hardening semantics cannot be presented as unchanged `2026-08`; P6 owns promotion to a new machine-readable revision. |
| Authorization is `isAllowed(principal, toolName)` and denials are generic `LogicException`/`StateError`. | `CommitAuthorizer`, protocols | P2 needs subject/tenant/resource/action/environment context and stable redacted semantic errors. |
| PHP reference coordinator passes principal/authorizer/time/descriptor into the protocol; Dart exposes protocol and authorizer from the top-level barrel but has no equivalent reference consumer. | reference slice, Composer PSR-4, Dart barrels | P2 migrates the real reference consumer and gates new direct calls; P7 owns final public export/removal. |
| Current spent-token guard is instance-local and is deliberately before current authorization. | protocols and F02 | P2 preserves the logical presentation/authorization boundary but labels replay guarantees partial; P3 owns atomic consume and denial outcome persistence. |

## Accepted boundary

The supported service has two application operations:

- prepare receives only action/tool intent, actual arguments and trusted-preview input; actor, time,
  descriptor, authorization target, policy and commit principal are resolved by injected ports;
- commit receives only opaque token and actual arguments; every security-critical value is loaded or
  recomputed by the service.

The composition root and the implementations of trusted ports remain a named trust assumption.
Safe-by-construction here means an ordinary per-call integrator cannot downgrade the control by
supplying booleans, stale values or an allow-all object; it does not mean a malicious trusted
composition root is harmless.

### Authoritative binding tuple

```text
tenant_id
proposal_id + proposal_version
action_id + tool/action
args_fingerprint + descriptor_digest
policy_id + policy_revision
approval_requirement (mode, quorum, separation predicate)
proposer_id + commit_principal_id + counted distinct approver_ids
expires_at
```

P2 validates this tuple against the signed token fields that already exist plus one authoritative
confirmation record and journal-backed approval evidence. P3 may later place more fields in a new
token envelope, but that representation must preserve the same semantic comparisons.

### Ordering contract

1. Obtain authenticated actor and current time from trusted ports.
2. Verify token integrity/shape and resolve the exact authoritative record.
3. Recompute actual-argument fingerprint and current descriptor; compare the whole available tuple.
4. Resolve current policy; missing, unknown, downgraded or incompatible revision denies.
5. Load journal-backed approval evidence; compare exact tuple, status, expiry, distinct approvers,
   quorum and separation rules.
6. Build context-rich authorization request and evaluate current authorization deny-by-default.
7. Cross the presentation/consume boundary; until P3 this remains only process-local evidence.

The implementation may place the current authorization callback inside the legacy presentation
primitive to preserve the existing “presented then denied is spent” behavior. P2 does not claim
that this is cross-process atomic; P3 must make the ordering and outcome durable.

## Alternatives

### A. Patch `dualControl` null handling only

Rejected. It still lets a caller pass `false`, stale time/descriptor, spoofed principal and a
permissive authorizer, and it leaves approval/token identity unchecked.

### B. Add a facade but keep the low-level method as an equally supported API

Rejected. A documented safe path does not remove the cheaper bypass path from reference consumers
or future integrations.

### C. Add safe facade, stage legacy deprecation, defer representation/removal

Accepted in D7. P2 makes the facade the only supported integration path and gates repository
consumers; P3/P5/P6/P7 retain their existing representation and compatibility ownership.

### D. Merge P2 into token/storage hardening

Rejected. It couples authorization semantics to one envelope/DDL choice and makes PHP/Dart API
migration impossible to review independently from cross-process replay and event persistence.

## Item decomposition

| Item | Product | Depends on | Why separate |
|:--|:--|:--|:--|
| P2.1 | security contract, migration inventory, adversarial cases | P1 contracts | One normative tuple/error/case owner must precede language code. |
| P2.2 | PHP/Dart trusted types and ports | P2.1 | Semantic model can be parity-tested without service orchestration or storage. |
| P2.3 | PHP/Dart safe service and structured denials | P2.2 | Runtime boundary consumes a frozen model and leaves consumer migration isolated. |
| P2.4 | migrated consumers, bypass validator, parity receipt | P2.3 | Final integration proves the supported call graph and hands export removal to P7. |

```text
P1.2/P1.3/P1.4 -> P2.1 -> P2.2 -> P2.3 -> P2.4
```

`confirmation-contract` groups P2.1–P2.2 because both consume P1 contracts and define the shared
vocabulary. `confirmation-service` groups P2.3–P2.4 because implementation, consumer migration and
full regression reuse the same service/test context. Both groups are `recommended:`; dependency-safe
subsets remain executable after D2's whole-plan design gate opens.

## Stable error intent

P2.1 freezes exact spellings, but the closed semantic classes are already fixed for execution:
policy missing/changed/denied, approval missing/stale/mismatched, separation/quorum violation,
tenant/context mismatch and current authorization denied. Externally they carry stable code,
category, retryability and safe remediation only; raw token, policy expression, subject/tenant IDs,
approver identities and hidden-resource existence stay out of the error. P5 later owns detailed
audit evidence, and P6 incorporates the codes into the next shared registry.

## Validation strategy

1. One machine-readable manifest enumerates every bypass/refusal and is consumed by both SDK suites.
2. Reflection/source-boundary tests prove forbidden security parameters are absent from the facade.
3. Exact-set parity compares case ID, result, semantic code, category and retryability.
4. A repository validator rejects new direct low-level calls outside the frozen implementation/test allowlist.
5. Frozen spec bytes remain unchanged; residual work is qualified to P3/P5/P6/P7.
6. PHP/Dart full tests and analysis remain green; a happy-path-only service cannot close P2.

## Risks retained by design

- Trusted DI adapters can still be malicious or misconfigured; P2 names this assumption and tests
  per-call non-injectability, not the integrity of an arbitrary composition root.
- Process-local spent-token state remains insufficient until P3.
- Journal-backed proposal/approval ports have no production persistence semantics until P5.
- Additive types/deprecation do not define the final package surface; P7 must decide archive/exports.
- Frozen `2026-08` cannot advertise P2 semantics; P6 must publish/version the shared machine contract.
