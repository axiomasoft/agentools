# Conformance runner

`conformance/bin/run` исполняет векторы класса `behavior` ревизии `2026-08` против отдельного
процесса-адаптера. Диалог определён в `11_conformance-harness.md` и использует протокол `1.1`:
`start → config → run × N → stop`. Фикстуры и ожидания принадлежат раннеру; адаптер реализует
поведение и пишет в stdout только протокольные JSON-строки.

Локальный прогон:

```sh
composer install --working-dir=conformance
php conformance/bin/run --adapter 'php conformance/tests/fixtures/adapters/probe.php' --vectors conformance/tests/fixtures/probe-corpus --spec-rev 2026-08 --report out/conformance-report.json
```

Опциональный `--rules` выбирает срез по `spec_rule_ids`: точные идентификаторы и шаблоны
пространства `PREFIX-*` перечисляются через запятую. Список нормализуется в отчёте;
невалидный selector и selector с нулевым результатом завершают прогон ошибкой.

Harness protocol `1.1` не передаёт адаптеру `expect`, `id`, `title` или `vector_class`:
`run.vector` содержит только `spec_rev`, `spec_rule_ids`, `conformance_level`, `given`, `script`.
Фактические вызовы границ идут парой `probe_call`/`probe_result`; `tool_calls` и
`observations` раннер строит из этого transcript и отвергает их в итоговом `result` адаптера.

Коды возврата: `0` — все проверки прошли; `1` — наблюдается отказ SUT, включая тайм-аут или
завершение адаптера; `2` — ошибка протокола или самого раннера; `3` — неверный вызов CLI.

Статусы отчёта закрыты PHP-enum: `pass`, `fail`, `warn`, `not-testable`,
`baseline-expected`; статуса `skip` не существует. Строгость отказа берётся из
`rules.json`, а `not-testable` допускается только с дословной причиной правила. Baseline — JSON
object с ключами `<vector-id>` или `<vector-id>:<check-id>`; каждая запись содержит `reason`,
`owner`, `since`, `issue`. Пока у репозитория нет issue-tracker (Q-2), `issue` указывает на
адрес плана, например `P6.4` или `Q-2`. Пустой `baseline/reference.json` намерен: эталонный
адаптер обязан оставаться зелёным без исключений; исчезнувшая baseline-запись — ошибка.

Эталонный адаптер появится в P6.2/P6.3. Он не является источником семантики: нормой остаются
замороженные ревизия и корпус векторов.

## Локальный CI-гейт и честная граница remote

`python3 tools/ci_gate.py --all` — единственный носитель состава и строгого порядка четырёх
слоёв CI. `.github/workflows/ci.yml` вызывает только его слои, поэтому локальная проверка и
workflow не получают расходящуюся логику. Ветка слоя 2 сейчас соответствует отсутствию решения
владельца по `DR-02` §6: SDK-часть прямо названа не имеющей предмета, а свежесть трёх производных
артефактов ревизии всё равно проверяется.

Remote `origin` настроен, но запуск CI и merge protection не наблюдались этой фазой и остаются
непроверенными. Поэтому local gate и отдельные отрицательные прогоны каждого слоя — обязательное
доказательство; наличие workflow не объявляется доказательством блокировки merge.
