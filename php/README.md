# AgenTools PHP SDK

`agentools/sdk` — Composer-пакет с namespace `AgenTools\` для реализации
протокола AgenTools revision `2026-08`. Минимальная версия PHP — 8.3.

Это тонкая библиотека контрактов, валидаторов и детерминированных примитивов;
она не является Laravel-интеграцией, вторым агентным runtime или эталонным
conformance-адаптером. Интеграцию с `corex/ai` реализует потребляющий контур.

## Зависимости и качество

Пакет фиксирует lockfile в репозитории, чтобы локальные и CI-прогоны использовали
одни и те же версии. Зависимости намеренно ограничены линиями, совместимыми с
объявленным PHP 8.3: `opis/json-schema:^2.6`, `symfony/uid:^7.4` и
`mmccook/php-json-canonicalization-scheme:^1.0`; QA использует
`phpunit/phpunit:^12` и `phpstan/phpstan:^2.2`.

Локальная версия PHP может быть новее 8.3, поэтому зелёный прогон сам по себе не
является доказательством нижней границы. Это доказывается констрейнтами зависимостей
и отсутствием в исходниках конструкций, требующих PHP 8.4.

```bash
composer install --working-dir=php
composer test --working-dir=php
composer analyse --working-dir=php
```

Conformance-адаптер живёт в `php/conformance/adapter.php`. Тесты пакета входят в слой 3
CI с регистрацией SDK в `conformance/sdks.json` (item P7.12).

**Owner-gate `DR-02` §6 — ветка (C):** решения владельца по SDK-части слоя 2 нет.
`tools/ci_gate.py` печатает «SDK-часть слоя 2 — без предмета…»; чекер структурной
сверки «поле схемы ↔ поле типа SDK» **не пишется** (это решило бы owner-gate за владельца).
Открытый вопрос: Q-12.
