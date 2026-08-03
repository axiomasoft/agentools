# HANDOFF — 2026-08-03 — after P4.3

**Next:** /task:plan-run AGENTOOLS P4.4

| Параметр | Значение |
|:--|:--|
| Model | implementation/high |
| Context | cold-start — plan-step |
| Команда | `/task:plan-run AGENTOOLS P4.4` |

```text
/task:plan-run AGENTOOLS P4.4
```

**Done:**

- P4.1 был атомарно переоткрыт и снова закрыт (`e4d7c6a`, D138): behavior-вектор
  получил `given.identity.{tenant_id,session_id}` и identity preseed-записи; golden
  получил отрицательный raw-JSON кейс с `.error.json`.
- P4.3 закрыт (`a7b463d`): `AOB-1..44` и `IDE-1..43` покрыты, а `IDE-44..48`
  остаются нормативно `not_testable`; `COV({'08_audit-observability.md',
  '09_idempotency-errors.md'})` = `[]`.
- JCS набор сверён с `cyberphone/json-canonicalization/testdata`; `bmp-weird` —
  отдельный literal-UTF-8 случай вне BMP. Golden-кейсы теперь имеют ровно один
  результат: `.golden.txt` или `.error.json`, и обязательный `.meta.json`.
- `python3 tools/spec_lint.py --fixtures-check fixtures/2026-08` и
  `python3 tools/spec_lint.py spec/revisions/2026-08` — `OK`.

## Формат, который должен читать P6

- Дедупликация в behavior-векторах сравнивает запись по `tenant_id`, `tool_name`,
  `idempotency_key`; `session_id` сохраняет контекст, но не входит в ключ.
- Для отрицательного JCS-кейса раннер должен передать байты `.input.json` без
  предварительного объектного decode и ожидать код из `.error.json`; успешный кейс
  остаётся обычным байтовым сравнением с `.golden.txt`.

**Remaining:**

- P4.4 — GREASE, DoS и пара открытая-провод/закрытый-авторинг.
- P4.7 — сценарии, долгие операции и безопасность.
- P4.8 — статические и harness-кейсы.
- P4.5 — единственный владелец итогового отчёта coverage и `ГЕЙТ A3`; не поднимать
  `min_vectors` и не генерировать `coverage.md` раньше него.

**Sources of truth:** `phases/P4.md` (P4.1/P4.3 Completion Notes) · `plan.md` D138 ·
`fixtures/2026-08/README.md` §2/§7 · `tools/spec_lint.py`.

## Open risks

- P4.2 Pending Work (principal/dual-control, descriptor drift, конкурентная запись)
  не менялся: D138 добавляет только identity для dedupe и не решает эти носители.
- Golden error-case расширяет формат корпуса; P6 обязан реализовать его до первого
  фактического прогона, иначе `AOB-37` останется только структурно проверенным.

write-site: pre_mutation: allow · pre_final: allow
