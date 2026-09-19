---
type: findings
plan: AGENTOOLS
phase: P9
created: 2026-09-19
layer: 1
---

# P9 — датированное измерение контура Flex/CoreX (2026-09-19)

Слой 1 (`plan-protocol` §16): сырьё, полученное прогоном команд и RAG в item P9.1.
Опора сравнения — `findings/P9-flex-corex-recon-2026-07-27.md`. Синтез — `research/08-p9-crm-slice-2026-08.md`.

**Якоря деревьев на момент измерения:**

| Дерево | Команда | Хеш |
|:--|:--|:--|
| vault Brain | `git -C ~/Vaults/Brain rev-parse --short HEAD` | `616396a` |
| монорепо CoreX | `git -C ~/projects/packages/corex rev-parse --short HEAD` | `0ca85d2` |
| приложение flexcrm | `git -C ~/projects/flexcrm rev-parse --short HEAD` | `929a7c3` |
| agentools (bookkeeping) | `git -C ~/projects/packages/agentools rev-parse --short HEAD` | `50a6811` |

**Кontроль «чужие репозитории не тронуты»** (зафиксировано ДО работы item'а и сверено ПОСЛЕ):

```console
$ git -C ~/projects/packages/corex status --short | wc -l
266
$ git -C ~/projects/flexcrm status --short | wc -l
1198
```

---

## 1. Пакет `corex/ai` — по-прежнему НЕ СУЩЕСТВУЕТ

```console
$ ls ~/projects/packages/corex/packages/
api  attributes  auth  core  crm  import-export  intl  media  modules
notifications  panels  recurrence  search  search-intl  search-meilisearch
tasks  tasks-workflow  tenancy  transfer  wakeup  workflow

$ ls ~/projects/packages/corex/packages/ai
ls: cannot access '/home/vostrikov/projects/packages/corex/packages/ai': No such file or directory
```

**Расхождение с разведкой 2026-07-27:** состав монорепо **расширился** (появились `api`, `attributes`, `import-export`, `intl`, `media`, `notifications`, `panels`, `recurrence`, `search*`, `tasks-workflow`, `transfer`, `wakeup`, `workflow`), но **`packages/ai` по-прежнему отсутствует** — факт §1 прежней разведки сохраняется.

```console
$ grep -rn "agentools\|AgenTools" --include=*.php --include=*.json -l ~/projects/packages/corex | grep -v vendor
(пусто)
```

---

## 2. Версии стека — `composer.lock` обоих репозиториев

Команда:

```bash
python3 -c "import json; …"  # см. прогон сессии P9.1
```

| Пакет | CoreX (`0ca85d2`) | flexcrm (`929a7c3`) | 2026-07-27 (recon) CoreX | 2026-07-27 flexcrm |
|:--|:--|:--|:--|:--|
| `laravel/framework` | v13.32.0 | v13.31.0 | v13.22.0 | v13.15.0 |
| `laravel/reverb` | НЕТ | v1.11.1 | НЕТ | v1.10.2 |
| `laravel/mcp` | НЕТ | **v1.0.0** | НЕТ | НЕТ |
| `laravel/ai` | НЕТ | НЕТ | НЕТ | НЕТ |
| `filament/filament` | НЕТ | v5.8.1 | НЕТ | v5.6.7 |
| `corex/core` | dev-main | 0.3.2-alpha.20260915 | dev-main | dev-main |
| `stancl/tenancy` | НЕТ | НЕТ | НЕТ | НЕТ |

**Порог PHP:** CoreX `^8.3` · flexcrm `^8.5` (без изменения класса расхождения).

**Именованные расхождения с §2 recon 2026-07-27:**
- Laravel minor drift в обоих контурах (13.22→13.32, 13.15→13.31).
- **`laravel/mcp` появился в flexcrm** (v1.0.0) — в recon не был установлен ни в одном репозитории.
- **`laravel/ai` по-прежнему не установлен** ни в CoreX, ни в flexcrm.
- `corex/core` в flexcrm **перестал быть `dev-main`** — пин alpha-сборки.

---

## 3. Ре-верификация версий по первоисточникам (2026-09-19)

| Факт | Значение на дату исполнения | Источник |
|:--|:--|:--|
| `laravel/ai` — последний стабильный релиз | **v0.11.2**, опубликован 2026-09-03 | `RAG:✅ 2026-09-19` [packagist.org/packages/laravel/ai](https://packagist.org/packages/laravel/ai) |
| `laravel/ai` — HITL/approval | dev-ветка **`dev-fix/multi-step-approval-resume`** (не `dev-tool-approval-human-in-the-loop`) | тот же Packagist JSON |
| `laravel/mcp` — последний стабильный релиз | **v1.0.0**, опубликован 2026-09-14 | `RAG:✅ 2026-09-19` [packagist.org/packages/laravel/mcp](https://packagist.org/packages/laravel/mcp) |
| Laravel — текущая мажорная линия | Laravel 13, релиз **2026-03-17**, PHP **8.3–8.5** | `RAG:✅ 2026-09-19` [laravel.com/docs/13.x/releases](https://laravel.com/docs/13.x/releases) |

**Расхождения с `findings/P9-flex-corex-recon-2026-07-27.md` §6 (поимённо):**
- `laravel/ai` v0.10.1 (2026-07-21) → **v0.11.2** (2026-09-03) — новый minor.
- `laravel/mcp` v0.9.x → **v1.0.0** — мажорный скачок; flexcrm уже на v1.0.0 в lock.
- HITL dev-ветка переименована (`dev-tool-approval-human-in-the-loop` → `dev-fix/multi-step-approval-resume`); **стабильный API Approvable/approval_state по-прежнему не подтверждён**.
- Laravel 13 / PHP 8.3–8.5 — **расхождений нет**.

---

## 4. Живой канон CoreX — ADR-020, ADR-022, B-32

### 4.1 Фронтматтер

```console
$ grep -n '^updated:' ~/Vaults/Brain/05-Projects/03-Packages/CoreX/docs/03_Dev/ADR/ADR-020*.md \
  ~/Vaults/Brain/05-Projects/03-Packages/CoreX/docs/03_Dev/ADR/ADR-022*.md
ADR-020: updated: 2026-07-03
ADR-022: updated: 2026-07-12
```

### 4.2 Git-история vault (после 2026-07-27)

```console
$ git -C ~/Vaults/Brain log --oneline -3 -- \
  "05-Projects/03-Packages/CoreX/docs/03_Dev/ADR/ADR-020 AiGateway.md" \
  "05-Projects/03-Packages/CoreX/docs/03_Dev/ADR/ADR-022 Agentic Surface Norm.md"
a58f8ae docs(plan): FLEX-2 P5.2 закрыт — … ADR-022 …
8804fdd docs(flex): экосистема Flex — CoreX+FlexCRM … (#101)
```

Коммит `a58f8ae` позже 2026-07-27 касается ADR-022 в контексте FLEX-2 P5.2; **`updated:` в фронтматтере ADR-020/022 не менялся** с дат разведки. Содержательная сверка §3 recon (AiGateway middleware, ToolRegistry, confirm-гейт ADR-022 п.6) **не отменена** этим измерением — детальная таблица B-32 §3.8 остаётся опорой P9.2.

### 4.3 B-32 §3.0–§3.9

Перечитан `05-Projects/01-Products/FlexCRM/plans/flex/30_blueprints/B-32_agentic-surface.md` §3.0–§3.9. Расхождения П1–П7 с ревизией `2026-08` — **без изменения** относительно `findings/P9-…` §5 (опора сравнения, не повтор).

---

## 5. Чужой план FLEX-2 — P6.15 и открытые вопросы

### 5.1 Status Board

Источник: `05-Projects/01-Products/FlexCRM/plans/FLEX-2/artifacts/migration-source-v1/phases/P6.md` (актуальная структура item'ов; live Status Board синхронен):

| ID | Title | Status |
|:--|:--|:--|
| P6.15 | corex/ai (B-32 agentic-норматив + runtime) → GREEN | ⬜ Not started |

**Расхождение с 2026-07-27:** статус **не изменился** — item по-прежнему не стартовал.

### 5.2 `Scope Excluded` P6.15 — дословно (SDK)

> SDK AgenTools (Q28 Deferred — нормативная ссылка, не кодовая зависимость v1)

### 5.3 Пин `laravel/ai` / `laravel/mcp` в спеке P6.15

> Версии `laravel/ai`/`laravel/mcp` — пин v0.8.2 (elicitation отсутствует, П6); освежить RAG на scaffold'е.

**Фактический lock flexcrm на 2026-09-19:** `laravel/mcp` **v1.0.0** установлен; `laravel/ai` **не установлен**. Пин v0.8.2 в чужом item'е **устарел** относительно Packagist (§3) — ожидаемо по тексту item'а («освежить RAG на scaffold'е»).

### 5.4 Строки `Q25`/`Q26`/`Q28`/`Q58`/`Q59` плана FLEX-2

| ID | Статус (FLEX-2 open-questions, vault) |
|:--|:--|
| Q25 | Decision pending (152-ФЗ sign-off) |
| Q26 | Exploration (голосовой канал) |
| Q28 | Deferred — «SDK AgenTools … v1»; B-32 §3.8: норматив от SDK не зависит |
| Q58 | Decision pending (переименование 24 канонных CRM-тулз) |
| Q59 | Decision pending (носитель декларации agentic) |

**Q28 не снят** — кодовая зависимость от SDK по-прежнему исключена в P6.15.

---

## 6. Достаточность зрелости PHP SDK — пересечение «правила среза × красные векторы»

**Источник уровней:** `artifacts/P7-gate.md` + `conformance/reports/php-levels.md` (дерево agentools `50a6811`).

| Уровень | pass | fail | warn |
|:--|--:|--:|--:|
| L1 | 126 | **0** | 0 |
| L2 | 56 | **0** | 0 |
| L3 | 31 | **0** | 0 |

**Красные векторы отчёта `ГЕЙТ P7`:** множество **пусто** (fail = 0 на всех уровнях; подтверждено `out/php-all.json`: fails 0).

**Правила сценария среза** (документы `05_clarification-mrtr.md`, `06_scenarios.md` + ядро `CNF-*`/`ALC-*`/`IDE-*` из `03_action-lifecycle.md`, `04_confirmation.md`, `09_idempotency-errors.md`):

| Класс | Кол-во идентификаторов | Диапазон |
|:--|--:|:--|
| `SCN-*` | 30 | SCN-1 … SCN-30 |
| `CLR-*` | 21 | CLR-1 … CLR-21 |
| `CNF-*` (ядро) | 41 | CNF-1 … CNF-41 |
| `ALC-*` (ядро) | 37 | ALC-1 … ALC-37 |
| `IDE-*` (ядро) | 48 | IDE-1 … IDE-48 |

**Пересечение «правила среза × красные векторы отчёта `ГЕЙТ P7`»:** **пересечение пусто**.

**Следствие для Q-15 (D107):** по измеренным числам P7 (L2 = 56/56, L3 = 31/31) и пустому пересечению — **достаточность зрелости PHP SDK для фазы P9 по этой механике закрыта**; поднятие `declared_level` выше измеренного остаётся отдельным owner-вопросом, не блокером P9.1.

---

## 7. Пакет решения владельцу

### 7.1 Развилка дома кода (ветка A / B / C)

| Ветка | Что означает на **2026-09-19** | Цена |
|:--|:--|:--|
| **(A) `corex/ai` + зависимость на `agentools/sdk`** | Пакета нет; потребует создания `packages/ai` и снятия/переворота Q28 FLEX-2 | Согласование с владельцем CoreX + правки чужого плана; код в чужом репо |
| **(B) `corex/ai` без SDK + тонкий мост** | Пакета нет; мост всё равно ждёт P6.15 или параллельного каркаса | Третий пакет; двойная поверхность ToolRegistry/AiGateway |
| **(C) `corex/ai` не создан** | **Факт сегодня** — P6.15 ⬜ Not started, каталога нет | **(C1)** waiting до FLEX-2 P6.15 · **(C2)** мост поверх `corex/core`+`flex/crm`+app с последующим переносом |

**Рекомендация автора:** ветка **(C2)** — разрешённый владельцем мост поверх существующего контура, потому что P6.15 не стартовал, Q28 остаётся Deferred, а `laravel/mcp` v1.0.0 уже в flexcrm без `laravel/ai` (развод трёх гейтов в INT-001 становится критичнее, чем ожидание чужого майлстоуна без SLA).

**Выбор ветки — гейт владельца; item его не делает.**

**Owner gate (2026-09-19):** владелец выбрал **(C2)** → D184. Мост поверх `corex/core`+`flex/crm`+app; P9.2 разблокирован.

### 7.2 Развилка зрелости PHP SDK

| Ветка | Когда | Цена |
|:--|:--|:--|
| **(i) Follow-up item в P7 (реопен)** | Если бы пересечение было непустым | Срыв календаря P9; повторный гейт SDK |
| **(ii) Сужение Scope среза** | Если бы пересечение было непустым | Урезанный сценарий CRM; запись в отчёт среза |
| **(iii) Принятие риска** | Если бы пересечение было непустым | Явная строка риска в P9-slice-report |

**Факт P9.1:** пересечение **пусто** — ветки (i–iii) **не активированы** для этой фазы по D107.

**Рекомендация автора:** **не реопенить P7**; зафиксировать в Q-15 числа L2/L3 = 100 % и пустое пересечение; Known deviation projection-catalog из P7-gate остаётся честностью conformance, но не пересекается с правилами среза.

---

## 8. Команды измерения (воспроизводимость)

```bash
ls ~/projects/packages/corex/packages/
git -C ~/projects/packages/corex rev-parse --short HEAD
git -C ~/projects/flexcrm rev-parse --short HEAD
git -C ~/Vaults/Brain rev-parse --short HEAD
# composer.lock — python3 скрипт сессии P9.1
grep -rn "agentools\|AgenTools" --include=*.php --include=*.json -l ~/projects/packages/corex | grep -v vendor
git -C ~/Vaults/Brain log --oneline -3 -- "…/ADR-020 …" "…/ADR-022 …"
# Packagist: curl packagist.org/packages/laravel/{ai,mcp}.json
# Laravel releases: laravel.com/docs/13.x/releases
git -C ~/projects/packages/corex status --short | wc -l
git -C ~/projects/flexcrm status --short | wc -l
```
