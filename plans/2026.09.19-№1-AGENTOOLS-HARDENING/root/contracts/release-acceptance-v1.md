# Release acceptance contract v1 — AgenTools hardening (PLAN1)

**Version:** v1  
**Status:** draft contract (P10.1)  
**Decision anchor:** D15 · **Depends on:** terminal P1–P9 receipts

## 1. Scope

This contract defines the **exact claim/evidence matrix** and **two non-collapsing verdict axes**
for private release readiness versus public eligibility. It does not authorize publication,
registry upload, tags, or credential use.

| Axis | Meaning |
|:--|:--|
| `private_ready` | Exact P9 subjects, current P1–P9 evidence, zero unresolved private blockers |
| `public_eligible` | No unresolved public blocker rows (legal/channel/topology/capability) |
| `public_authorized` | Always `false` in machine output until `[OWNER-GATE:G-RELEASE]` |

A green `private_ready` **never** sets `public_authorized=true`.

## 2. Row classes

| Class | Upstream owner examples |
|:--|:--|
| `guarantee` | `G-*` from `hardening-guarantees-v1.md` |
| `non_guarantee` | `NG-*` |
| `resource_limit` | `RL-*` from `resource-envelope-v1.md` |
| `threat` | `TM-*` from `threat-model-v1.md` |
| `finding` | `F01`–`F14` audit rows |
| `migration` | `M-*` from migration inventory |
| `support_dimension` | `SUP-*` compatibility/support tuples |
| `semantic_namespace` | Agentools, JCS oracle, semantic profile, official MCP (separate) |
| `package_surface` | `PKG-*` stable/advanced inventory anchors |
| `release_subject` | `SUBJ-*` P9 normalized archives |
| `adversarial` | `AV-*` deferred or proved vectors |
| `capability` | Hosted attestation and unavailable plan capabilities |
| `decision_gate` | Owner decisions (D5 public identity) |

## 3. Closed states

| State | Private non-blocking | Public non-blocking |
|:--|:--|:--|
| `verified` | yes | yes |
| `non_applicable` | yes | yes |
| `baseline_pending` | no | no |
| `capability_unavailable` | yes* | no |
| `decision_pending` | yes* | no |
| `failed` | no | no |
| `stale` | no | no |

\*Only when the contract row explicitly records the disposition; hosted attestation remains
`capability_unavailable` and cannot become `verified` without attested evidence.

## 4. Forbidden claims

Machine and public docs must not claim, without applicable proof rows:

- exactly-once external side effects
- official MCP certification (use applicability matrix wording)
- universal SQL down-migration
- hosted GitHub attestation when status is `unavailable-plan`
- public registry URLs while `LICENSE` is proprietary and Dart `publish_to: none`

## 5. Machine artifacts

| Artifact | Role |
|:--|:--|
| `release-acceptance-v1.schema.json` | JSON Schema for matrix receipt |
| `artifacts/P10-acceptance-matrix.json` | Digest-qualified rows + verdicts |
| `tools/release_acceptance.py` | Deterministic capture/validate |

Namespaces **Agentools semantics**, **JCS oracle**, and **official MCP scenario compatibility**
remain separate evidence classes; they must not collapse into one pass rate.

## 6. Non-goals

- Re-running child conformance suites inside P10.1
- Mutating upstream P1–P9 contracts or receipts
- Consumer execution (P10.3) or owner G-RELEASE packet (P10.5)
