# HANDOFF — 2026-09-20 — after foundation batch P1.1–P1.3

**Next:** task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P1.4-P1.5

| Параметр | Значение |
|:--|:--|
| Batch | n/a |
| Model | frontier — Codex gpt-5.6-sol |
| Thinking | xhigh |
| Capabilities | independent read-only whole-plan audit; repository recon; primary-source verification if a changing premise is material; Task adapter |
| Context | cold-start-root: design-root-terminal |
| Суть | Close P1.4–P1.5 guarantees/resource contracts on top of baseline + threat + compatibility artifacts |

```session-continuity-decision/v1
{"evidence": ["batch:80871c2d0d8e854043b0a2936c41ae5095aa9b9901eb396587a4451d98b98b04", "loaded-inputs:not-reusable", "cold-start-cost:46", "checkpoint:PLAN1.execution-routing:journal-sha256:8795f4b75616fd194bf3a68301b8a3b2603e50cd19d7565a6851a4c6752640d1"], "outcome": "cold-start-root", "reason": "design-root-terminal", "runnable": true, "schema_version": "session-continuity-decision/v1"}
```

**Native Codex command:**

```bash
codex -C /home/vostrikov/projects/packages/agentools -m gpt-5.6-sol -c 'model_reasoning_effort="xhigh"' -a never -s danger-full-access '$ task:plan-audit '"'"'2026.09.19-№1-AGENTOOLS-HARDENING'"'"' design'
```

**Done:** foundation batch P1.1–P1.3 GREEN — `tools/hardening_baseline.py` + `artifacts/P1-baseline.json`,
`findings/P1-baseline.md`, `root/contracts/threat-model-v1.md`, `root/contracts/compatibility-support-v1.md`,
`findings/P1-migration-inventory.md`. Inherited baseline RED (conformance layers 3–4, dart analyze,
publish dry-run, composer validate) зафиксированы, не исправлялись (D6).

**Remaining:** P1.4–P1.5 contracts batch; independent whole-plan design audit (P0.1) still open;
P2+ product execution waits on P1 closure and audit gate. Grok batch selector still unverified.
Public release remains owner-authorized after private acceptance.

**Sources of truth:** `plans/2026.09.19-№1-AGENTOOLS-HARDENING/plan.md` ·
`roadmap.md` · `decisions/D16-execution-batches-and-launch-models.md` ·
`research/P2-design.md` · `research/P3-design.md` · `research/P4-design.md` ·
`research/P5-design.md` · `decisions/D15-private-acceptance-public-authority.md` ·
`decisions/D4-release-gate.md` · `decisions/D5-publication-identity.md`

**Open risks:** exact Grok 4.6 selector is not yet registered in the installed Task route-catalog;
D5 is still proposed; current identity is proprietary/private; both Dart packages have
`publish_to: none`; the MCP companion still has a path dependency and is not pub.dev-ready;
hosted attestation and repository governance remain capability-qualified.

**Workarounds/Deferred/Open questions:**

- workarounds: Codex route capture required the marketplace repository root in `PYTHONPATH` because
  the installed projection imported `core.provider`; browser search failed at transport level, so
  P10 RAG used direct read-only HTTPS fetches from the same official primary sources.
- deferred: all P1–P10 product execution until GREEN whole-plan design audit; any public action
  until a future exact G-RELEASE grant cites the P10.5 packet digest.
- open_questions: D5 legal/license/package identity, exact public channels and support route; hosted
  topology for `agentools_mcp_dart`; observed release/attestation/governance capabilities at launch.
