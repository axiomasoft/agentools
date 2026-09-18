# AgenTools Dart SDK

Два pub-пакета-сиблинга в `dart/`:

| Пакет | Путь | Назначение |
|:--|:--|:--|
| `agentools` | `dart/agentools/` | Ядро: контракты, валидация, детерминированные примитивы (pure Dart) |
| `agentools_mcp_dart` | `dart/agentools_mcp_dart/` | Спутник: реализация порта `McpTransport` на `mcp_dart` (item P8.12) |

Транспорт намеренно вынесен из ядра (D86): `dart/agentools/pubspec.yaml` не содержит
`mcp_dart`, и гейт фазы P8 не проверяет сетевой транспорт.

## Порог Dart SDK

Минимальная версия — **Dart 3.11** (`environment.sdk: ^3.11.0`). Она задаётся
dev-зависимостями (`test:^1.31` требует SDK ^3.11.0; `lints:^6.1` — ^3.8.0), а не
локальным интерпретатором. Зелёный прогон на более свежем Dart не доказывает нижнюю
границу; её подтверждают констрейнты зависимостей и отсутствие в исходниках
конструкций новее объявленного порога.

Runtime-пины ядра: `json_schema:^5.2.2`, `ulid:^2.0.1`. JCS реализуется в пакете
самостоятельно (P8.3).

## Ревизия и conformance

Пакет реализует ревизию **`2026-08`**. Объявление живёт в `dart/agentools/pubspec.yaml`
(`agentools.spec_rev`) и читается `conformance/bin/lockstep`.

Conformance-адаптер Dart — `dart/agentools/conformance/adapter.dart` (item P8.13).
Регистрация SDK в `conformance/sdks.json` — тоже P8.13.

## Границы CI

- Тесты ядра входят в **слой 3** CI только после регистрации Dart SDK (item **P8.13**).
- Пакет-спутник **`agentools_mcp_dart` в `conformance/sdks.json` не попадает** — у него
  нет адаптера и векторов; его тесты остаются вне четырёхслойного гейта.

## Чем пакет не является

- не Flutter-пакет и не UI-контур Axioma;
- не второй агентный runtime;
- не эталонный conformance-адаптер;
- не транспортный слой (`mcp_dart` живёт в спутнике).

Интеграцию с `axioma_assistant` / `axioma_mcp` реализует программа Axioma, не этот
репозиторий.

```bash
dart pub get --directory dart/agentools
dart analyze dart/agentools
dart test --directory dart/agentools
```
