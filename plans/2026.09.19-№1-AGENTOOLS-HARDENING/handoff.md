# HANDOFF — 2026-09-20 — after P8.3

**Next:** run-items: task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P8.4

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | continue-root |
| Суть | PostgreSQL multi-process crash/interleave schedules without SQLite substitution |

**Provider-native command:**

```bash
agent --workspace /home/vostrikov/projects/packages/agentools --model grok-4.6 --force --sandbox disabled --trust --approve-mcps 'task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P8.4'
```

**Done:** P8.1–P8.3: adversarial-verification contract and AV-001–022 manifest; deterministic corpus/differential; 26/26 catalog kills; Infection 0.35.4 combined covered MSI 75.68 with floors 70/70.
**Remaining:** P8.4 PostgreSQL schedules; P8.5 resource envelopes and aggregate receipt; P0.1 design audit; P9 CI; P10 RC.
**Sources of truth:** plans/2026.09.19-№1-AGENTOOLS-HARDENING/plan.md · plans/2026.09.19-№1-AGENTOOLS-HARDENING/phases/P8/P8.md · fixtures/hardening/adversarial/manifest.v1.json · artifacts/P8-mutation-baseline.json
**Open risks:** Infection enum-in-class exclusion for LongOperationCoordinator.php; herd-lite PHP cannot load PCOV — baseline used /usr/bin/php8.4.
**Workarounds/Deferred/Open questions:**
- workarounds: Infection source exclude for LongOperationCoordinator.php; coverage driver is system PHP 8.4 + PCOV
- deferred: CRP-JCS-10K and CRP-SEM-213 remain declared not proved; P8.4/P8.5
- open_questions: —
