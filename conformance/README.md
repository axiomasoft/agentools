# Conformance runner

`conformance/bin/run` исполняет векторы класса `behavior` ревизии `2026-08` против отдельного
процесса-адаптера. Диалог определён в `11_conformance-harness.md` и использует протокол `1.0`:
`start → config → run × N → stop`. Фикстуры и ожидания принадлежат раннеру; адаптер реализует
поведение и пишет в stdout только протокольные JSON-строки.

Локальный прогон:

```sh
composer install --working-dir=conformance
php conformance/bin/run --adapter 'php conformance/tests/fixtures/adapters/echo-ok.php' --vectors fixtures/2026-08 --spec-rev 2026-08 --report out/conformance-report.json
```

Коды возврата: `0` — все проверки прошли; `1` — наблюдается отказ SUT, включая тайм-аут или
завершение адаптера; `2` — ошибка протокола или самого раннера; `3` — неверный вызов CLI.

Эталонный адаптер появится в P6.2/P6.3. Он не является источником семантики: нормой остаются
замороженные ревизия и корпус векторов.
