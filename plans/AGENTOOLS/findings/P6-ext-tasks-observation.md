# P6.3 — наблюдение Q-7: оба in-memory пути долгой операции

Дата: 2026-08-05.

Эталонный adapter прошёл полный corpus revision `2026-08` без MCP-транспорта. Два независимых
пути `DR-01` §G1 доказаны поведением в `full.json`:

- синхронный: `longop-001` — завершённое действие с `transport: synchronous`;
- task-channel: `longop-002`, `longop-003`, `longop-004`, `longop-102` — binding/status,
  включая input-required и failure-маршрут.

Команда: `php conformance/bin/run --adapter 'php conformance/adapters/reference/adapter.php'
--vectors fixtures/2026-08 --spec-rev 2026-08 --report
conformance/adapters/reference/reports/full.json`.

Результат: `213 pass, 0 fail, 213 total`; corpus SHA-256
`ff7976475127ea584e4b5e7149b821e1a90a17d5839fbea155455d9ca5921cd1`, зафиксированный
admission P6.2, связан с отчётом через `conformance/adapters/reference/reports/manifest.json`.

Это наблюдение не утверждает сроки зрелости экспериментального MCP Tasks и не закрывает Q-7:
остаточный транспортный факт относится к P8, как определено D70.
