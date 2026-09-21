# Ревизия 2026-09

**Статус:** candidate (PLAN1 hardening successor; не frozen, публичный тег не выпускается)

**Базовая ревизия:** `2026-08` (frozen, tag `spec-2026-08`, байты не меняются).

**Назначение:** самодостаточная candidate-ревизия, интегрирующая исполненные контракты P2–P5
(hardening confirmation, token replay, lease/fencing idempotency, EventStore/audit) поверх
неизменённого modern-only каркаса `2026-08`. Нормативные delta — документ `12_hardening-semantics.md`
(префикс `HRD-`), расширенная таблица кодов документа `09`, корпус `fixtures/2026-09/`.

Lockstep для candidate: идентификатор `2026-09` = имя каталога `spec/revisions/2026-09`.
Digest-qualified lock — `revision.lock.json` (генерируется tooling; ручная правка запрещена).
Публикация tag `spec-2026-09` — только после G-RELEASE (D4, P9/P10).

Пин MCP: [`spec/vendor/mcp/2026-07-28/PIN.md`](../../vendor/mcp/2026-07-28/PIN.md).

## Состав

Документы `00`–`11` — перенос норм `2026-08` с обновлёнными ссылками ревизии и совместимости;
содержательные delta P2–P5 — `12_hardening-semantics.md` (`HRD-`).

| Документ | Префикс | Назначение |
|:--|:--|:--|
| `00_overview.md` … `11_conformance-harness.md` | как в `2026-08` | каркас ревизии |
| `12_hardening-semantics.md` | `HRD-` | P2–P5 hardening semantics, bounds, migration hooks |

Схемы — `schema/*.json`, `$id` вида `https://agentools.dev/schema/2026-09/<file>`.
Закрытый авторинг — `schema/authoring/` (`tools/close_schema.py`).

Производные: `rules.json` (`tools/rules_index.py`), `semantic-manifest.json`
(`tools/semantic_manifest.py`, P6.2), `revision.lock.json` (P6.5 gate).

Корпус: `fixtures/2026-09/` (наследие `2026-08` + hardening cases).

## Проверка

```
python3 tools/spec_lint.py spec/revisions/2026-09
python3 tools/rules_index.py --check spec/revisions/2026-09
python3 tools/close_schema.py spec/revisions/2026-09 --check
python3 tools/spec_lint.py --fixtures-check fixtures/2026-09
```
