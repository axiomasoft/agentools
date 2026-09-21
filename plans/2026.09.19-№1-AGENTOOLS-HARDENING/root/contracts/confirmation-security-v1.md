# Confirmation security contract v1 — AgenTools (PLAN1)

**Version:** v1
**Status:** P2 executable contract
**Evidence date:** 2026-09-20
**Cases:** `fixtures/hardening/confirmation-security-v1.json`

## Supported boundary and trust

`ConfirmationService` is the supported application boundary. `prepare` accepts only action ID,
tool name, actual arguments and preview. `commit` accepts only opaque token and actual arguments.
Actor, tenant, current time, descriptor, context, policy, approval evidence and authorization are
constructor-injected trusted ports. Per-call callers cannot weaken these controls.

`ConfirmationProtocol` remains a deprecated expert/legacy compatibility primitive for focused
tests and the service implementation. The composition root and trusted port implementations are an
explicit trust assumption; a compromised composition root is outside this guarantee.

## Roles and binding tuple

Authenticated actor, proposer, policy-selected commit principal and distinct counted approvers are
separate roles. Every record and approval binds:

1. tenant ID;
2. proposal ID and positive proposal version;
3. action ID and tool/action name;
4. arguments fingerprint and descriptor digest;
5. policy ID and revision;
6. approval mode, quorum and proposer-separation predicate;
7. proposer, commit principal and distinct counted approver IDs;
8. expiry instant.

Raw token bytes are never audit/log identifiers. Stores use a SHA-256 token digest and proposal
identity. P2 does not change the frozen `2026-08` token layout.

## Prepare and commit ordering

Prepare obtains actor/time, context, descriptor and policy; refuses missing/disabled/expired trusted
data; issues the legacy signed token; and persists the authoritative record.

Commit performs these steps:

1. obtain authenticated actor and trusted time;
2. verify token integrity/shape and load the exact authoritative record;
3. compare tenant, token digest, proposal/version, action/tool, principal, expiry, actual arguments
   and current descriptor;
4. resolve current policy and compare ID, revision, commit principal and approval requirement;
5. if required, load journal-backed evidence and compare the entire tuple, status, expiry, distinct
   approvers, quorum and proposer separation;
6. require an explicit allow for a context-rich authorization request;
7. cross the legacy presentation boundary.

Structural/binding failures occur before presentation. Authorization is current and deny-by-default.
Process-local spend remains `baseline-partial`; P3 owns durable atomic consume and denial ordering.

## Fail-closed invariants

- Missing/unknown record, policy, descriptor, actor or required evidence denies.
- Changed, downgraded, disabled or expired policy denies.
- Any tuple mismatch denies, including tenant, proposal/version, arguments, descriptor and policy.
- Duplicate approvers are invalid; quorum is at least one; a proposer cannot be counted when policy
  selects separation. Separation/quorum are organization policy, not universal OWASP/NIST law.
- Revoked/expired evidence and revoked current authorization deny.
- Unknown decisions and exceptions never become allow.

## Stable external refusals

| Condition | Code | Category | Retryable |
|:--|:--|:--|:--|
| token invalid | `agentools.validation.confirmation_token_invalid` | validation | false |
| record missing | `agentools.precondition.confirmation_record_missing` | precondition | false |
| context/tenant mismatch | `agentools.permission.confirmation_context_mismatch` | permission | false |
| policy missing/disabled | `agentools.permission.confirmation_policy_denied` | permission | false |
| policy changed/downgraded | `agentools.precondition.confirmation_policy_changed` | precondition | false |
| approval missing | `agentools.permission.confirmation_approval_missing` | permission | false |
| approval stale/revoked/expired | `agentools.precondition.confirmation_approval_stale` | precondition | false |
| approval tuple mismatch | `agentools.precondition.confirmation_approval_mismatch` | precondition | false |
| insufficient quorum | `agentools.permission.confirmation_quorum_insufficient` | permission | false |
| proposer counted when forbidden | `agentools.permission.confirmation_separation_violation` | permission | false |
| current authorization denied | `agentools.permission.confirmation_authorization_denied` | permission | false |

Existing confirmation argument, expiry, spent, principal and descriptor-drift codes stay compatible.
Errors never expose raw tokens, subject/tenant IDs, approver identities, policy internals or hidden
resource existence.

## Source-of-truth and staged ownership

`ConfirmationRecordStore` and `ApprovalEvidenceReader` are journal-backed record/approval
projection seams, never second approval truth. P3 owns token/atomic consume, P5 durable journal/event
storage, P6 the successor shared schema/error registry, and P7 final expert API/export removal.
`spec/revisions/2026-08/**` stays byte-identical.
