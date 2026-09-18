---
id: D149
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Post-A3 source-amendment `CFH-55` закрывается фазово, без ложного carrier и без пересечения P3→fixtures.** P3.22 владеет `00`/`11` + производным `rules.json`; P3.23 выпускает authoring A2 через отдельные зелёные selftests и exact-set разбор единственной ошибки полного линтера `[CFH-55 → P4.12]`. P4.12 расширяет test-only `harness-case` входом `mcp_errors` и создаёт positive/negative пару exact-vs-wrong code; P6.6 исполняет её и wrong-code mutant. P4.13 возвращает полный `spec_lint` без `--allow-missing` в green до A3. Отклонено: добавить fixture в P3.22 (ломает phase-first), пометить `Not testable`/ослабить MUST (маскирует testable public contract), приписать CFH-55 существующему кейсу (ложный sole carrier), объявить красный полный линтер зелёным. **Consequences:** supersedes D146 только в части прежнего ожидания, что P3.23 завершится полным coverage-green; платим временным явно названным gap между A2 и A3 и расширением P4.12/P6.6, зато сохраняем границы фаз и различающую проверку.

## Почему

`phases/P3.md` P3.22 escalation/P3.23; `phases/P4.md` P4.12/P4.13; `phases/P6.md` P6.6; `research/13-p3-red-audit-remediation-2026-08.md` §7. `RAG:— (repo-grounded)`
