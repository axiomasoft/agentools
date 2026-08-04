# P4 — итоговый гейт A3 после observational remediation

**Дата:** 2026-08-04

**Якорь дерева:** item P4.19; source-коммит фиксируется в Completion Notes. Детерминированный
якорь содержимого manifest: SHA-256
`777060cdb9f028c9106ec0168a88ef846e7ebeddb44c68b2b9ca17eaf265a520`.

Исторические A3 P4.13 и Gate B round 1/2 не переписываются: их факты сохранены в git и в
`P5-gate-b.md`. Этот отчёт supersedes только утверждение прежнего A3 о достаточности
relation-only evidence (D161/D162).

## Команды гейта

```text
python3 tools/spec_lint.py --observational-carriers-selftest fixtures/2026-08
python3 tools/spec_lint.py --observational-carriers-check fixtures/2026-08
python3 tools/spec_lint.py --gate-b-remediation-write fixtures/2026-08
python3 tools/spec_lint.py --gate-b-remediation-check fixtures/2026-08
python3 tools/spec_lint.py --fixtures-check fixtures/2026-08
python3 tools/spec_lint.py spec/revisions/2026-08 --coverage --check
python3 tools/rules_index.py --check spec/revisions/2026-08
python3 tools/close_schema.py --check spec/revisions/2026-08
git diff --exit-code 80c25a6 -- spec/revisions/2026-08
git diff --check
```

## Условия гейта

| Условие | Наблюдаемый факт | Вердикт |
|:--|:--|:--|
| Exact remediation | manifest v2 содержит 51 строку: 38 behavior + 13 artifact, `gaps=[]` | PASS |
| Harness ownership | пять закрытых observation types; schema-positive/schema-invalid/self-report selftest 5/5 | PASS |
| Behavior carriers | 76 schema-valid members; каждый из 38 rules имеет exact pair и harness-owned observation | PASS |
| Artifact carriers | 13 raw negative/positive pairs; checker выводит verdict из candidate/reference/run outcomes | PASS |
| Shortcut registry | `projection_source`, `write_back_upcast`, `source_of_truth`, hash/route verdict flags, baseline booleans и magic carriers — 0 | PASS |
| Mutation strength | 51/51 addressable self-report/raw-fact mutants отвергнуты; pure manifest mutations 51/51 отвергнуты | PASS |
| Byte stability | write → check не меняет bytes; manifest SHA-256 `777060cd…265a520` | PASS |
| Формат корпуса | `--fixtures-check` = 0 | PASS |
| Coverage | 400 rules; 394 testable; MUST/MUST NOT 383/383; пять исторических SHOULD warnings | PASS с предупреждениями |
| Индекс и closure | `rules_index --check`: 400; `close_schema --check`: 15 | PASS |
| P3 source snapshot | diff от remediation-base `80c25a6` по `spec/revisions/2026-08` пуст; manifest source digest `24ce17d2…300215` | PASS |
| Чистота diff | `git diff --check` = 0 | PASS |

## Счёт корпуса

`coverage.md` свеж: 214 behavior, 89 artifact, 13 harness и 10 golden carriers. Семь
non-target rules, ранее агрегированных с негодными target carriers, сохранены отдельными raw
carriers D168; покрытие 383/383 не достигнуто ложным multi-rule positive.

## Граница вердикта

**ГЕЙТ A3: PASS.** В отличие от прежнего formal A3, этот PASS включает ownership и реальные
mutation rejects exact 51 F9–F15. Он всё ещё не является Gate B: независимый cold-start
аудит P5.1 round 3 обязан заново сверить нормативную строку, raw stimulus, boundary observation
и mutant каждого правила. Только его semantic GREEN разблокирует P5.3 и freeze/tag P5.2.
