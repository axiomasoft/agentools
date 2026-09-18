# P2 — разведка и репро перед исполнением фазы A1 (слой 1, 2026-07-27)

> Сырьё сессии `plan-design AGENTOOLS P2` (2026-07-27). Здесь — только факты с источником и
> воспроизводимой командой. Синтез и решения — `plan.md` §5 (D29–D34) и `phases/P2.md`.
> Повод захода: попытка `plan-run AGENTOOLS P2.1` завершилась `blocked`
> (`journal.jsonl`, ts `2026-07-26T22:03:20Z`, friction `routing-blocked`).

---

## 1. Репро блокера: §3 Routing не парсится компилятором Task Contract

Компилятор `packages/task/lib/task/task_contract.py` строит `pre_mutation`-границу и обязан
разобрать route-ячейку строки `## 3. Routing` для item'а. Разбор — `raw_cell.partition("/")`:
левая часть обязана быть именем класса (`economy`/`implementation`/`frontier`) либо
однозначным именем модели из `configs/command-routing.json` (`haiku`/`sonnet`/`opus`,
`gpt-5.6-luna`/`-terra`/`-sol`), правая — членом лестницы `('low','medium','high','xhigh','max')`.
Любой хвост в ячейке ломает правую часть.

Команда (из `~/projects/packages/swissknifeman/packages/task`):

```bash
PYTHONPATH=lib python3 -c "
from task import task_contract as tc
P='/home/vostrikov/Vaults/Brain/05-Projects/03-Packages/AgenTools/plans/AGENTOOLS'
for item in ['P2.1','P2.5','P1.1']:
    try: print(item,'OK',tc.build(P,item,actual_route={'model_class':'implementation','effort':'high'})['route'])
    except Exception as e: print(item,'FAIL',e)
"
```

Наблюдаемый вывод (на дереве до правки этой сессии):

```text
P2.1 FAIL P2.1: Routing: route cell 'implementation/high (sonnet/high)': effort 'high (sonnet/high)' is outside the frozen ladder ('low', 'medium', 'high', 'xhigh', 'max')
P2.5 FAIL P2.5: Routing: route cell 'implementation/high (sonnet/high)': effort 'high (sonnet/high)' is outside the frozen ladder (...)
P1.1 FAIL P1.1: Routing: route cell 'implementation/medium (sonnet)': effort 'medium (sonnet)' is outside the frozen ladder (...)
```

**Вердикт:** дефект не в компиляторе и не в фазе P2 — он в форме ячеек §3 всего плана:
подсказка провайдера в скобках («`(sonnet/high)`») попадала в поле effort. Блокировались **все**
items плана, включая готовую к исполнению фазу P1. `RAG:— (repo-grounded:
packages/task/lib/task/task_contract.py:250–261 `_resolve`, :46–47 `MODEL_CLASS_ORDER`/`EFFORT_ORDER`,
configs/command-routing.json `mappings.*.class_selectors`)`.

Второе следствие того же разбора: строка §3 подбирается по колонке `Items` **точным** совпадением
id либо диапазоном `^(P\d+)\.(\d+)\s*[–-]\s*(P\d+)\.(\d+)$` (`task_contract.py:93`,
:263–272). Форма `P3.*` не матчится **никогда** — фазовые черновики `P3.*`…`P10.*` дали бы тот же
`blocked` при первом же исполнении своей фазы. `RAG:— (repo-grounded: task_contract.py:93, :263–272)`.

## 2. Окружение исполнителя (перепроверено 2026-07-27)

```bash
php -v; composer --version; dart --version; node -v; npx --version; python3 -V
```

| Инструмент | Версия | Значение для фазы |
|:--|:--|:--|
| PHP | 8.4.1 (cli, NTS) | требование `wol-soft` (PHP ≥8.4) выполнимо |
| Composer | 2.8.3 | песочница `bakeoff/php/` устанавливается |
| Dart SDK | 3.12.2 stable (linux_x64) | песочницы `bakeoff/dart/`, `bakeoff/dart-transport/` |
| Node | 24.12.0 | `quicktype` через `npx` |
| npx | 11.6.2 | — |
| python3 | 3.12.3 | мок-сервер stdlib (P2.4), генерация JSON |

`RAG:— (окружение исполнителя, команды выше, 2026-07-27)`. Подтверждает и уточняет
`research/01-target-structure-2026-08.md` §3 (там та же таблица без версий npx/python).

## 3. Кандидаты кодогенерации — внешние факты

| Факт | Вердикт | Источник |
|:--|:--|:--|
| `quicktype` имеет таргеты **и `php`, и `dart`** (`--lang php`, `--lang dart`) в текущем публичном списке языков | подтверждено | `RAG:✅ 2026-07-27 (quicktype.io, quicktype.io/php, quicktype.io/dart)` |
| `wol-soft/php-json-schema-model-generator`: документирован **только Draft 7**; автодетект `$schema` при неизвестном объявлении **откатывается на Draft 7**; класса/опции для 2020-12 в публичном API нет | 2020-12 как первоклассный таргет НЕ подтверждён | `RAG:✅ 2026-07-27 (github.com/wol-soft/php-json-schema-model-generator)` |
| `swaggest/php-code-builder` (+ `swaggest/php-json-schema`): Draft 7/6/4, 2020-12 не заявлен | не кандидат по драфту | `RAG:✅ 2026-07-27 (github.com/swaggest/php-json-schema)` |
| `opis/json-schema`: draft 2020-12/2019-09/07/06 — **валидатор, не генератор** | подтверждает пин D15, кодогенерацию не закрывает | `RAG:✅ 2026-07-27 (github.com/opis/json-schema)` |
| `mcp_dart`: stable **2.2.2**; prerelease-линия **2.3.0-dev.x** (`dev.1`/`dev.2`/`dev.3` в разных срезах страницы) — именно она про MCP 2026-07-28, сохраняя совместимость с 2025-11-25; лицензия **MIT** | подтверждает `R-10` §4; точную dev-версию берёт исполнитель `dart pub` на дату прогона | `RAG:✅ 2026-07-27 (pub.dev/packages/mcp_dart)` |

**Следствие для дизайна фазы (несущее).** PHP-генератор — Draft-7-ориентированный, зрелого
schema-first генератора для Dart нет (`R-11` §10). Значит исход «в обоих языках DTO пишутся
руками» — не экзотика, а **вероятный** результат прогонов, а он прямо задевает решение
`DR-01` §J6 («каркас типов из схем»). Два следствия, внесённые в фазу:

1. Контрольная точка «DTO руками» нужна **симметрично в обоих языках** (была только в P2.3):
   без замера цены ручных DTO на стороне PHP решение §J6 по PHP в item'е P2.5 было бы догадкой.
2. Ветка эскалации P2.5 (конфликт факта с §J6) — основной сценарий, а не «на всякий случай»;
   формулировка item'а обязана вести исполнителя по ней без импровизации.

Оговорка честности: «Draft 7 в документации» ≠ «падает на 2020-12». Ровно это и меряет фаза —
вердикт ставится по наблюдаемому выводу прогона, а не по этой таблице.

## 4. Артефакты фазы против `.gitignore` репозитория (репро)

Корневой `.gitignore` репозитория `~/projects/packages/agentools` содержит `*.log` (а также
`/php/vendor/`, `/dart/.dart_tool/` — якорь `/` привязывает их к корню, каталога `bakeoff/` они
не покрывают). Items P2.2–P2.4 обязаны **закоммитить** логи прогонов
(`bakeoff/results/*.log`) — при живом `*.log` `git add` по ним молча ничего не делает, и
Deliverables item'а не попадают в item-коммит, хотя локальная Validation (`ls | wc -l`) зелёная.

Репро механики вложенного `.gitignore` (переисключение работает, т.к. `*.log` исключает файлы,
а не каталог-родитель):

```bash
cd /tmp && rm -rf gitest && mkdir gitest && cd gitest && git init -q .
printf '/php/vendor/\n*.log\n/dart/.dart_tool/\n' > .gitignore
mkdir -p bakeoff/results bakeoff/php/vendor
printf 'php/vendor/\ndart/.dart_tool/\n!results/*.log\n' > bakeoff/.gitignore
touch bakeoff/results/php-x.log bakeoff/php/vendor/a.php bakeoff/php/composer.lock
git add -A && git status --short
```

Вывод:

```text
A  .gitignore
A  bakeoff/.gitignore
A  bakeoff/php/composer.lock
A  bakeoff/results/php-x.log
```

**Вердикт:** `bakeoff/.gitignore` с явным `!results/*.log` решает проблему без правки корневого
`.gitignore`; `vendor/`/`.dart_tool/` под `bakeoff/` обязаны быть исключены там же (корневые
якорные правила их не ловят); lock-файлы песочниц (`composer.lock`, `pubspec.lock`) **коммитятся**
— они и есть доказательство того, какие версии кандидатов прогонялись.
`RAG:— (git 2.x, репро выше, 2026-07-27)`.

## 5. Два репозитория на один item — факт раскладки

Дом плана — vault (`Home: brain`, коммит `88109c9` перенёс планы в vault-папки проектов),
артефакты кода — репозиторий `~/projects/packages/agentools` (последний коммит `83cb540`).
Значит закрытие любого code-producing item'а этой фазы физически идёт **двумя коммитами в двух
разных репозиториях**: item-коммит — в `agentools`, bookkeeping-коммит — в vault `Brain`.
Топология `plan-protocol` §8 (два коммита) сохраняется, но хеш item-коммита для handoff берётся
из репозитория `agentools`. `RAG:— (repo-grounded: git log 05-Projects/.../AGENTOOLS → 88109c9;
git -C ~/projects/packages/agentools log → 83cb540; plan.md Meta `Home: brain`)`.
