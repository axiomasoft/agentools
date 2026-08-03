# Ревизия 2026-08

Статус: **draft** — в разработке. Статус снимается гейтом фазы **P5 (B)** плана
`plans/AGENTOOLS` (адверсариальный аудит согласования с обязательным вердиктом
GREEN) плюс git-тегом ревизии; каталог при этом НЕ переименовывается — имя
`2026-08` финальное с момента создания (D19).

Ревизия — **modern-only** против MCP `2026-07-28`. Легаси-лексика протокола
(перечислена в разделе «Out of scope by design» документа `00_overview.md`)
запрещена во всех документах, включая формулировки «поддерживается для
совместимости» (D20). Предыдущая ревизия — история, не патчится (D18).

Пин версии протокола, против которого пишется ревизия:
[`spec/vendor/mcp/2026-07-28/PIN.md`](../../vendor/mcp/2026-07-28/PIN.md)
(коммит спеки + байты `schema.json`, зафиксированы фазой P1).

## Состав

| Документ | Префикс правил | Назначение | Статус |
|:--|:--|:--|:--|
| `00_overview.md` | `OVR-` | конвенции, глоссарий, RFC 2119, уровни L1–L3, out of scope by design | написан (`OVR-1`…`OVR-27`) |
| `01_extension-identity.md` | `EXT-` | идентичность расширения, грамматика вендорных ключей, negotiation | написан (`EXT-1`…`EXT-17`) |
| `02_tool-contract-profile.md` | `TLP-` | профиль инструмента, `descriptor_digest`, `content_origin` | написан (`TLP-1`…`TLP-34`) |
| `03_action-lifecycle.md` | `ALC-` | журнал как источник истины, эпоха, снапшот, хеш-цепочка | написан (`ALC-1`…`ALC-37`, переходы `ALC-T1`…`ALC-T38`) |
| `04_confirmation.md` | `CNF-` | подтверждение: токен, решения, TTL, dual control | написан (`CNF-1`…`CNF-41`) |
| `05_clarification-mrtr.md` | `CLR-` | уточнения и единственный биндинг MRTR | написан (`CLR-1`…`CLR-21`) |
| `06_scenarios.md` | `SCN-` | сценарии, грамматика выражений (только AST), лимиты, saga | написан (`SCN-1`…`SCN-30`) |
| `07_long-operations.md` | `LOP-` | длинные операции: оба пути обязательны | написан (`LOP-1`…`LOP-27`) |
| `08_audit-observability.md` | `AOB-` | таксономия аудита, канонизация JCS, трассировка | написан (`AOB-1`…`AOB-44`) |
| `09_idempotency-errors.md` | `IDE-` | ключ и fingerprint, три состояния, коды, must-ignore | написан (`IDE-1`…`IDE-48`) |
| `10_security.md` | `SEC-` | запрет проброса токенов, тройка принципалов, происхождение аргумента | написан (`SEC-1`…`SEC-19`, своих схем не вводит) |
| `11_conformance-harness.md` | `CFH-` | harness-протокол, severity, baseline, CI, golden, lockstep | написан (`CFH-1`…`CFH-54`, схема `harness-protocol.json`) |

Схемы — `schema/*.json` (диалект 2020-12, `$id` вида
`https://agentools.dev/schema/2026-08/<file>`). Опубликованные схемы **открытые**:
неизвестные ключи и значения игнорируются (`DR-01` §E3/§J9). Закрытый вариант
живёт в `schema/authoring/` и **пишется только генератором**
`tools/close_schema.py` (D42) — ручная правка закрытых схем запрещена.
Файл `schema/expression.md` — не JSON-схема, а единственный носитель грамматики
языка выражений; он нормативен целиком в силу правила `SCN-13` документа
`06_scenarios.md`.

Индекс правил [`rules.json`](rules.json) — производный артефакт, генерируется
`tools/rules_index.py` (item P3.14), руками не пишется: 399 записей
(`{rule_id, doc, normative_word, level, not_testable, text_digest}`), сортировка
детерминирована (документ, затем числовая часть маркера). Корпус тест-векторов
ревизии — `fixtures/2026-08/` (фаза P4).

Гейт выхода фазы P3 (`ГЕЙТ A2`) закрыт — вердикт и числа:
`plans/AGENTOOLS/artifacts/P3-gate-a2.md` (vault-репозиторий `Brain`).

## Проверка

```
python3 tools/spec_lint.py spec/revisions/2026-08
python3 tools/spec_lint.py --namespace-inventory spec/revisions/2026-08
python3 tools/spec_lint.py --namespace-inventory-selftest
```

Namespace inventory — машинный регрессионный контракт решения D144: он отдельно
перечисляет carriers домена и vendor-префикса, их сортированное объединение и
проверяет пин 32 domain / 35 domain+vendor файлов.

Все 12 документов написаны — линтер прогоняется **без** `--allow-missing` (флаг
существовал на время написания ревизии; ГЕЙТ A2 требует его отсутствия). Индекс
правил: `python3 tools/rules_index.py --write spec/revisions/2026-08` (генерация) /
`--check` (расхождение с закоммиченным → exit 1, ручная правка `rules.json`
запрещена). Точечные режимы линтера: `--keywords-check <файл схемы> [--list A|B]`
(ключевые слова против Списков `DR-02` §2–§3) и `--legacy-check
spec/revisions/2026-08` (легаси-лексика). Свежесть закрытых схем:
`python3 tools/close_schema.py spec/revisions/2026-08 --check`.
