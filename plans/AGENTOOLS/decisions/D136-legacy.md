---
id: D136
date: 2026-07-28
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Эскалация P4.3 разобрана владельцем по трём пунктам, два закрыты реопеном закрытых items, один вынесен в open-questions.** (1) L3/Files — ложная тревога, `check_fixtures_format`/`COV` не привязывают `conformance_level` к подкаталогу `l1/l2/l3`, объяснение вписано в ToR P4.3, `Files` расширен на `vectors/l3/**` для консистентности с P4.2/P4.7 (не строго обязательно). (2) golden/COV — реальная дыра: Phase-Context COV-скрипт (`phases/P4.md`) сужен с исключения ВСЕГО `/golden/` до исключения только `*.input.json`/`*.golden.txt`; введена конвенция golden-компаньона `<имя>.meta.json` (README.md §7, `agentools`) с проверкой `check_golden_meta` (`tools/spec_lint.py`, три негативных/позитивная проба подтверждены). (3) Схема — реальная дыра, ЧАСТИЧНО закрыта: реопен P4.1 (`fixtures/2026-08/schema/vector.json`, был 🟢 Done) добавил `LlmStub.toolCall.meta.idempotency_key` (носитель `_meta["dev.agentools/action"].idempotency_key`, IDE-1..4) и `preseed.idempotency[].{key,args_fingerprint}` (IDE-16) — оба поля опциональны, `additionalProperties:false` не снят, существующий корпус (P4.2/P4.6 + незакоммиченная волна P4.3/P4.4/P4.7/P4.8) остаётся валиден (`--fixtures-check` OK), анти-регрессия №6 переподтверждена эмпирически (`bakeoff/dart`, офлайн-резолв новых `$ref`). Реопен P3.11/P3.14 (были 🟢 Done, фаза P3 терминальна): `09_idempotency-errors.md` `IDE-44..48` получили маркер `Not testable` (ревизия не вводит депрекированных core-значений — правило признаёт это само в тексте §9), `rules.json` перегенерирован (`tools/rules_index.py --write`, 397 правил без изменений, `not_testable` 1→6), `ГЕЙТ A2: PASS` не отменяется. **IDE-5** (область ключа — тенант/инструмент/ключ) НЕ решена — потребовала бы моделирования multi-tenant/multi-session в `Given`, архитектурное решение большего масштаба двух точечных полей — вынесена в `open-questions.md` Q-29, P4.3 ею не блокируется (Pending Work с адресом). Попутная находка: Validation-команда №6 P4.1 (`grep -rc ... \

## Почему

grep -v ':0' \
