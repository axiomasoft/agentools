# P6 — фазовый conformance-гейт

**Вердикт:** `ГЕЙТ P6: BLOCKED — P6.5 не доставил обязательные отрицательные golden-адаптеры byte-drift.php и struct-drift.php; доказательство golden-механики не полное.`

**Якорь дерева артефактов:** `dc43349` (`git rev-parse --short HEAD`). Все числа ниже получены на этом коммите указанными командами.

## Пять условий гейта

| Условие | Статус | Доказательство |
|:--|:--|:--|
| Эталонный адаптер прогоняет весь корпус | PASS | `php conformance/bin/run --adapter 'php conformance/adapters/reference/adapter.php' --vectors fixtures/2026-08 --spec-rev 2026-08 --baseline conformance/baseline/reference.json --report out/gate.json` → 213 pass / 0 fail, exit 0; `len(report.vectors) == len(fixtures/2026-08/vectors/*/*.json)` → `True` |
| Мутант фиксирует расхождение | PASS | `php conformance/bin/run … --vectors conformance/tests/fixtures/mutants --spec-rev 2026-08 --report out/p6.9-mutant.json` → exit 1; `lifecycle-001`: `final_state: ожидалось "failed", получено "completed"` |
| Harness-протокол проверяется вместе с нарушениями | PASS | `php conformance/bin/harness-selftest --cases fixtures/2026-08/harness` → 13/13 cases pass, включая ожидаемые нарушения `CFH-38`, `CFH-55`, `CFH-3`, `CFH-11`, `CFH-47`, `CFH-6`, `CFH-4` |
| Производный отчёт по уровням детерминирован | PASS | `php conformance/bin/levels out/gate.json --write --check` → exit 0; `--out out/levels-probe.md --write` записывает только переданный путь |
| Локальный четырёхслойный CI зелёный | PASS | `python3 tools/ci_gate.py --all` → exit 0; слои 1/2/3/4 выполнили 2/3/1/3 проверки соответственно |

## Счёт прогона

| Метрика | Значение | Команда |
|:--|--:|:--|
| Behavior-векторов | 213 | `python3 -c "import json; print(len(json.load(open('out/gate.json'))['vectors']))"` |
| L1 / L2 / L3 | 126 / 56 / 31 | `python3 -c "import json; r=json.load(open('out/gate.json')); print(*[sum(v['level']==l for v in r['vectors']) for l in ('L1','L2','L3')])"` |
| Классы корпуса: behavior / artifact / harness / golden | 213 / 89 / 13 / 10 | `fixtures/2026-08/coverage.md` §«По классам корпуса» |
| Golden-пар в отчёте | 9 | `python3 -c "import json; print(len(json.load(open('out/gate.json'))['golden']))"` |
| Harness-кейсов | 13 | `find fixtures/2026-08/harness -name '*.json' -type f | wc -l` |
| Проверок CI L1 / L2 / L3 / L4 | 2 / 3 / 1 / 3 | `python3 tools/ci_gate.py --all` |

## Обязательные отрицательные прогоны

| Механизм | Вывод | Статус |
|:--|:--|:--|
| Матчер | Мутант `lifecycle-001` даёт `fail` и mismatch `final_state` | PASS |
| Статусы | `php -r '…Status::cases()…'` → `baseline-expected,fail,not-testable,pass,warn`; `skip` отсутствует | PASS |
| Baseline | `… --baseline conformance/tests/fixtures/baseline/stale.json` → exit 1: проходящая baseline-запись отвергнута | PASS |
| Golden | `test -f conformance/tests/fixtures/adapters/byte-drift.php` и `…/struct-drift.php` → exit 1: оба обязательных адаптера отсутствуют | **BLOCKED** |
| Lockstep | `php conformance/bin/lockstep --sdks conformance/tests/fixtures/sdks/mismatch.json` → exit 1: manifest `2026-07-draft`, требуется `2026-08` | PASS |
| CI | P6.7 Completion Notes фиксируют по одному красному входу на каждый слой, каждый `--layer N` → exit 1; локальный `--all` повторно зелёный | PASS |

## Что НЕ проверялось

- Remote CI и merge protection не запускались: Q-2 остаётся внешней границей.
- SDK-часть слоя 2 не имеет предмета без решения владельца по `DR-02` §6; CI прямо печатает ветку C.
- SDK ещё не зарегистрированы, поэтому слои 3/4 доказывались непустым собственным подмножеством раннера и эталонного адаптера.
- Два обязательных golden-отрицательных прогона не исполнены: P6.5 не содержит нужных fixture adapters. Это и есть причина `BLOCKED`, а не допускаемое исключение.
