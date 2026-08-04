# HANDOFF — 2026-08-04 — after P4

**Next:** manual: implementation/high

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | `command.subagents`, `command.workflow-carrier` |
| Context | cold-start — orchestration |
| Суть | Выполнить P4.15: вернуть недостающие typed driver-only inputs; только затем запускать W2. |
| Команда | `task:plan-run AGENTOOLS P4.15` |

```text
# D157: сохранить W2 candidate, закрыть P4.15 source+bookkeeping; затем native carrier W2
```

**Done:** Re-design P4.10 закрыл semantic DoR: D156 фиксирует 65 single-rule behavior pairs,
отдельный retained `longop-002/102`, exact `rule_evidence` и static-transfer `SEC-17`/`AOB-26`
в P4.12. W2 carrier проверяет этот контракт до close. Текущий candidate сохранён; его
`--fixtures-check`, полный spec lint и `git diff --check` зелёные, но broad pairs требуют fix.

**Remaining:** P4.15 добавляет closed policy/confirmation/audit-export driver inputs и selftest.
Только затем resume W2 проводит пятишардовую реконструкцию, exact evidence review,
единственную пятишардовую fix-итерацию и последовательное закрытие P4.12→P4.10→P4.11;
затем solo P4.13 выпускает A3/evidence-map,
`plan-close AGENTOOLS P4` и cold-start Gate B round 2 P5.1.

**Sources of truth:** `plans/AGENTOOLS/research/17-p4.10-behavior-carrier-dor-2026-08.md` ·
`plans/AGENTOOLS/phases/P4.md` P4.15/P4.10/P4.12 · `plans/AGENTOOLS/plan.md` D156/D157 ·
`plans/AGENTOOLS/research/16-p4-w2-dor-reconcile-2026-08.md` §5 · `plans/AGENTOOLS/roadmap.md` W2 ·
`plans/AGENTOOLS/workflows/wf-agentools-p4-remediation.js`.

**Open risks:** До P4.15 W2 fail-closed: policy/confirmation/audit stimuli нельзя прятать в
payload. После P4.15 shard-boundary снижает размер одного контекста, но общий aggregate всё равно
обязан остановиться при неполном exact-set, пересечении write-set, чужом dirty path или
Major/Blocker после единственной итерации — без частичного close. P4.13 не имеет права объявлять
semantic Gate B GREEN.

**Workarounds/Deferred/Open questions:**

- workarounds: —
- deferred: P4.13 ждёт terminal W2.
- open_questions: —
