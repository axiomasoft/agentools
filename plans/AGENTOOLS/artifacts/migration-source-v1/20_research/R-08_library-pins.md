# R-08 — Пины библиотек PHP/Dart: JCS, JSON Schema 2020-12, ULID

> Research Sweep AgentKit #2 (P0.2, 2026-07-10). RAG: perplexity-web (6 запросов, категория×язык).
> Факты без источника помечать [UNVERIFIED].

## Summary
Три категории × два языка. **JCS (RFC 8785):** PHP имеет 2 рабочих пакета (mmccook/php-json-canonicalization-scheme — активнее, обновлён 2026-04), Dart — НИ ОДНОГО (canonical_json не заявляет RFC 8785, не поддерживает floats). **JSON Schema 2020-12:** PHP — opis/json-schema (полная поддержка 2020-12/2019-09/07/06, Apache-2.0); Dart — json_schema (Workiva) поддерживает 2020-12 с v4.0.0+, лицензия `unknown` на pub.dev — юр-риск для проприетарного использования. **ULID:** PHP — symfony/uid (активный, часть Symfony 7.x, MIT) вытесняет robinvdvleuten/ulid (застыл на 2020-12-06, community мигрирует прочь); Dart — пакет `ulid` (2.0.1, сен 2024, null-safe), лицензия не подтверждена RAG. Итог подтверждает D5-fallback мастер-плана: JCS в Dart писать самим (спека 05_audit-events описывает алгоритм самодостаточно); JSON Schema и ULID — библиотеки есть в обоих языках, но лицензия Dart-пакетов требует точечной проверки перед P2/P3 (не блокер P0 — вне Scope этого item).

## Recommendation
- **JCS:** PHP → `mmccook/php-json-canonicalization-scheme` (MIT, форк официального JCS reference, активнее обновляется, проходит официальные JCS-тесты). Dart → реализовать JCS-подмножество самостоятельно (нет готового пакета в экосистеме вообще, не только с лицензионным риском) — это уже заложено в D5 плана как fallback, R-08 подтверждает необходимость.
- **JSON Schema 2020-12:** PHP → `opis/json-schema` (единственный с полной поддержкой драфта, Apache-2.0 совместима с proprietary). Dart → `json_schema` (Workiva) — функционально подходит (draft 2020-12 с v4.0.0+), но лицензия помечена `unknown` на pub.dev — **зафиксировать как открытый вопрос для P3 (Dart SDK)**: перед использованием проверить LICENSE-файл репозитория напрямую (pub.dev метаданные ненадёжны для лицензии), не блокирует P0 (спека не зависит от выбора валидатора).
- **ULID:** PHP → `symfony/uid` (не `robinvdvleuten/ulid` — тот де-факто мёртв с 2020, экосистема явно мигрирует, напр. `bentools/doctrine-ulid` помечен deprecated в пользу symfony/uid). Dart → пакет `ulid` (2.0.1) — единственный найденный кандидат, null-safe, но лицензия не подтверждена RAG — тот же открытый вопрос для P3, что и json_schema.

## Findings/Facts

### JCS — PHP
Два пакета на Packagist реализуют RFC 8785. `root23/php-json-canonicalization` — единственный релиз 1.0.0 (2023-09-26), MIT (via GitHub), PHP ≥8.0, без открытых issues/зависимых пакетов — низкая активность, но стабилен. `mmccook/php-json-canonicalization-scheme` — 1.0.0 (2023-08-07), MIT, PHP ^8.1, последнее авто-обновление Packagist 2026-04-09, 8420 установок, 3 открытых issue — форк официального JCS reference implementation, ориентирован на webhook-валидацию/криптографическую верификацию, соответствует официальным JCS-тестам.
[src: https://packagist.org/packages/root23/php-json-canonicalization; https://github.com/mmccook/php-json-canonicalization-scheme; https://datatracker.ietf.org/doc/html/rfc8785]

### JCS — Dart
Нет пакета на pub.dev, заявляющего соответствие RFC 8785. `canonical_json` — единственный релевантный «canonical JSON»-пакет, но НЕ ссылается на RFC 8785/JCS и явно НЕ поддерживает floating-point числа (расхождение с требованиями RFC 8785). Официальный реестр реализаций JCS (`cyberphone/json-canonicalization`) перечисляет Rust/JS/Java/Go/.NET/Python/Elixir/Ruby/PHP — Dart в списке отсутствует. Вывод: писать JCS-подмножество самим в Dart SDK.
[src: https://github.com/cyberphone/json-canonicalization]

### JSON Schema 2020-12 — PHP
`opis/json-schema` — полная поддержка драфтов 2020-12/2019-09/07/06 («all keywords from all drafts»), Apache-2.0, v2.6.0 (2025-10-17), Packagist: ~40.97M установок, 213 dependents, 645 stars, 12 open issues, последнее обновление 2026-04-30. `justinrainbow/json-schema` — только до Draft-2019-09 включительно, «newer draft features might not be supported» — БЕЗ полной поддержки 2020-12, MIT, v6.10.0 (2026-06-16), установок на порядок больше (~336M), но это legacy/compat-библиотека для старых драфтов, не кандидат для AgenTools (спека требует 2020-12).
[src: https://github.com/opis/json-schema]

### JSON Schema 2020-12 — Dart
`json_schema` (издатель workiva.com, pub.dev) — поддерживает драфты вплоть до 2020-12 (дефолт схемы — draft-7, требуется явно указать `SchemaVersion.draft2020_12` с версии 4.0.0+). Текущая версия на июль 2026 — 5.2.2, pub-score 115, 45 likes, ~372k загрузок, совместим с Dart 3. **Лицензия на странице pub.dev помечена `unknown`** — SPDX-строка не задекларирована в метаданных пакета на момент проверки (2026-07-10) — требует прямой проверки LICENSE-файла репозитория перед принятием решения в P3, НЕ в рамках P0.
[src: https://pub.dev/packages/json_schema; https://pub.dev/packages/json_schema/changelog]

### ULID — PHP
`symfony/uid` — активная линия 8.x (Symfony 7.x branch), непрерывные релизы (>100 тегов), последнее обновление Packagist 2026-04-29, MIT, полная UID-абстракция (UUID v1/v3-v8 + ULID), UUID v7 дефолт в Symfony 7.4 — время-упорядоченные ID. `robinvdvleuten/ulid` — последний релиз v5.0.0 датирован 2020-12-06, MIT, только Packagist auto-update с тех пор — фактически заморожен; сторонние интеграции (`bentools/doctrine-ulid`) явно помечены «no longer maintained», рекомендуют мигрировать на `symfony/uid` — экосистема сходится на Symfony.
[src: https://github.com/temporalio/sdk-php — контекст экосистемы; прямые источники в ответе RAG без явных URL для packagist-страниц symfony/uid и robinvdvleuten/ulid, версии/даты — из ответа Perplexity search, не перепроверены WebFetch]

### ULID — Dart
Пакет `ulid` на pub.dev — v2.0.1 (релиз сентябрь 2024, по данным Flutter Gems), null-safe, совместим с Android/iOS/Linux/macOS/web/Windows. **Лицензия не подтверждена** RAG-ответом (сам ответ признаёт: «exact license string is not exposed in the fetched content») — точно так же требует прямой проверки перед P3, отдельно от json_schema.
[src: https://fluttergems.dev/packages/ulid/]

## Sources
- https://packagist.org/packages/root23/php-json-canonicalization
- https://github.com/mmccook/php-json-canonicalization-scheme
- https://datatracker.ietf.org/doc/html/rfc8785
- https://github.com/cyberphone/json-canonicalization
- https://github.com/opis/json-schema
- https://pub.dev/packages/json_schema
- https://pub.dev/packages/json_schema/changelog
- https://fluttergems.dev/packages/ulid/

## Open Questions (перенос в P3 Dart SDK, вне Scope P0.2)
- Лицензия `json_schema` (Dart) — pub.dev показывает `unknown`; проверить LICENSE-файл репозитория напрямую перед выбором валидатора в P3.
- Лицензия `ulid` (Dart) — не раскрыта в доступных RAG-источниках; аналогичная проверка перед P3.
