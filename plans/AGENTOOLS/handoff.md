# HANDOFF — 2026-08-04 — after P4.10

**Next:** /task:plan-design AGENTOOLS P4.13

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high — пересобрать pair-level checker contract |
| Context | NEW SESSION — шаг-не-item |
| Суть | Спроектировать repair P4.13 для present/absent/length evidence extraction, не меняя закрытый P4.10 corpus. |
| Команда | `$ task:plan-design AGENTOOLS P4.13` |

```text
$ task:plan-design AGENTOOLS P4.13
```

**Done:** P4.10 закрыт item-коммитом `ea0a08f`: четыре CNF fixtures получили единый P4.15 confirmation snapshot; SEC-11 evidence address указывает на фактический `given.tools`; ALC/AOB fixtures остались на anchor `7a3840b`.

**Remaining:** P4.13 сначала переопределяет pair-level extraction для absent/length/value counterfactuals, затем вручную выпускает manifest и A3; после terminal P4.13 — close P4 и новый cold-start P5.1 round 2.

**Sources of truth:** `plans/AGENTOOLS/research/19-p4.10-counterfactual-reopen-2026-08.md` · `plans/AGENTOOLS/phases/P4.md` P4.10/P4.13 · `plans/AGENTOOLS/plan.md` D158 · `fixtures/2026-08/schema/vector.json` · current dirty `tools/spec_lint.py` (P4.13 only).

**Open risks:** `tools/spec_lint.py` и `fixtures/2026-08/coverage.md` содержат незакоммиченный P4.13 diff; P4.10 не включил их в item-коммит. P4.13 пока не имеет manifest/A3.

**Workarounds/Deferred/Open questions:**

- workarounds: —
- deferred: pair-level checker repair и manifest принадлежат P4.13.
- open_questions: —
