# P10 design — exact private acceptance and a non-authorizing public-release packet

**Date:** 2026-09-20  
**Tree anchor:** `445a8b29769fb918c846545190304494b1483981` (`main`)  
**Evidence class:** `RAG:—` unless a row points to `P10-design-rag.md`.

## Design question

How can PLAN1 accept one production-grade private PHP/Dart candidate from P1–P9 evidence,
prove that its documentation and migrations work from exact archive bytes, and prepare an
owner-decidable public release request without turning technical GREEN into legal/publication
authority?

## Repository observations

| Observation | Evidence | Design consequence |
|:--|:--|:--|
| Root/package READMEs still describe P0, revision `2026-08`, historical item IDs and pre-hardening boundaries. | `README.md`, `php/README.md`, `dart/README.md`, companion README | P10 needs a docs/evidence gate; prose cannot be accepted by visual review alone. |
| Top-level `SECURITY.md`, `SUPPORT.md`, `CHANGELOG.md` and release/operations guides do not exist. | tracked-file inventory | Documentation/support/security/migration truth is a real P10 product, not release-note polish. |
| The repository is private and `LICENSE` is proprietary. Composer says `proprietary`; both Dart packages use `publish_to: none`. | `LICENSE`, package manifests | Private acceptance is the default attainable outcome. Public identity remains D5/owner scope. |
| The MCP companion depends on core by `path: ../agentools`. | `dart/agentools_mcp_dart/pubspec.yaml`, D12 | It can be tested only as a paired private subject until a hosted topology is separately approved. |
| P1 creates one guarantee/support/resource vocabulary; P6–P9 already preserve separate semantic, MCP, adversarial, capability and subject namespaces. | P1/P6–P9 item specs and D11–D14 | P10 exact-joins these owners and must not replace them with one percentage or Boolean CI result. |
| P7 tests staged package surfaces; P9 later defines the exact normalized candidate bytes, SBOM and provenance. | P7.4, P9.3–P9.5 | Final consumer acceptance must use P9 subjects, not rebuild from the workspace or reuse P7 staging as release identity. |
| Historical AGENTOOLS P11 planned identity/docs/clean checkout/consumer work but was never executed and is frozen by D1. | `plans/AGENTOOLS/phases/P11/**`, D1 | Useful acceptance concepts are verified and re-owned by PLAN1; historical status/authority is not inherited. |
| D4 explicitly allows private RC completion without G-RELEASE; D5 is still proposed. | D4, D5, generated open questions | P10 must close private acceptance even when public eligibility remains false; a required publish item would contradict D4. |

## Accepted model

### 1. One normative claim/evidence graph

P10.1 creates `release-acceptance-v1` and an exact matrix derived from upstream normative IDs.
Every row names its owner, current digest, applicability, freshness and independent private/public
blocking dispositions. Agentools semantics, JCS, official MCP scenario compatibility, resource
timing, scanner freshness and hosted attestation remain separate evidence classes.

### 2. Documentation is executable release evidence

P10.2 reconciles root/package docs, security/support/versioning, API and migration/runbook text
against the matrix. Snippets compile or analyze against package interfaces. Current proprietary/
private identity remains explicit; no public registry URL, OSS claim, support SLA or security
contact is invented. Forward-safe SQL guidance names backup, rehearsal and recovery boundaries
instead of promising universal down migrations.

### 3. Final consumers use exact P9 bytes

P10.3 independently rehashes P9 subjects, installs them into disposable consumers and then executes
only documented flows. It does not read source paths after staging. The Dart MCP companion remains
paired-private. PostgreSQL migration/recovery/revocation drills use disposable namespaces and
observable state, not production data or sleep-based inference.

### 4. Dossier and judgement stay explicit

P10.4 recomputes all joins, retains every residual and reports two verdicts. Private readiness can
be GREEN only with no unresolved private blocker. Public eligibility remains false for unresolved
legal/channel/topology/capability rows. Neither verdict is publication authorization.

### 5. P10 accepts private bytes and only requests G-RELEASE

P10.5 binds private acceptance to source/tree and exact archive/SBOM/provenance digests. Its
G-RELEASE artifact is an immutable request containing exact identities, channels, version/tag,
actions, credential prerequisites, consequences and invalidation triggers. It cannot contain owner
consent. Any future public action is separate owner-authorized scope that cites that packet digest.

## Item decomposition

| Item | Product | Depends on | Why separate |
|:--|:--|:--|:--|
| P10.1 | acceptance contract/schema, exact matrix and validator | terminal P1–P9 evidence | One status/claim vocabulary must exist before docs or final judgement. |
| P10.2 | truthful docs, support/security/versioning and migration/operations guidance | P10.1 | Public prose changes can be validated independently from artifact execution. |
| P10.3 | exact-subject consumers and migration/recovery transcripts | P10.2 + P9 subjects | Execution proves documentation against immutable bytes rather than source-tree context. |
| P10.4 | final dossier and residual-risk ledger | P10.1–P10.3 + P1–P9 | Judgement recomputes evidence after consumers stabilize and cannot mutate product to obtain PASS. |
| P10.5 | private acceptance and G-RELEASE request | P10.4 | Final acceptance waits for immutable dossier; owner authority remains outside the item. |

```text
P1–P9 -> P10.1 -> P10.2 -> P10.3 -> P10.4 -> P10.5
                         exact P9 subjects -----^
```

## Alternatives rejected

### A. One final `ci_gate --all` as acceptance

Rejected. It loses claim ownership, capability/non-applicability states, evidence freshness and the
binding between one candidate's bytes and the asserted guarantees.

### B. Reuse P7 consumer smoke as final acceptance

Rejected. P7 proves boundary/staging behavior before P9 defines exact reproducible subjects,
SBOM/provenance and private-RC aggregation.

### C. Make public publication a mandatory P10 item

Rejected. D4 says absent G-RELEASE must not block private acceptance. A mandatory owner-gated item
would either prevent the phase closing or pressure an implicit authorization.

### D. Let P10 fix upstream semantics or tests when the dossier is red

Rejected. P10 returns each confirmed defect to its named owner through the normal repair/readiness
protocol. Acceptance criteria cannot be weakened in the aggregation layer.

### E. Treat registry retraction/deletion as rollback

Rejected. Published versions and tags create durable consumer state; response plans use a new
version, retraction/advisory or revocation as channel permits and never promise erasure.

## Validation strategy

1. Mutate claim/evidence rows for missing, extra, stale, duplicate and cross-subject evidence.
2. Compile/analyze every public snippet and forbid unsupported guarantee/certification language.
3. Rehash and install exact P9 subjects in no-source consumers under path/network sentinels.
4. Rehearse SQL install/upgrade/recovery and token/key revocation only in disposable environments.
5. Recompute the dossier from child bytes and corrupt every aggregate relation in fixtures.
6. Prove private/public verdict independence and reject self-authorized or wildcard G-RELEASE data.
7. Assert tags, releases, registries, credentials, legal identity and repository settings are unchanged.

## Risks retained

- D5 remains an owner decision; private acceptance deliberately does not settle license, vendor/
  publisher identity, public support channel or registry set.
- The Dart MCP companion is public-release-blocked while its path dependency remains.
- Hosted attestation and repository governance capabilities remain explicit states from P9; local
  provenance cannot replace an unavailable platform property.
- Scanner databases and capability observations are time-sensitive and carry expiry/recheck rules.
- A technically accepted private RC can still be unsuitable for a public business/legal launch;
  the split verdict is intentional, not a deviation.
