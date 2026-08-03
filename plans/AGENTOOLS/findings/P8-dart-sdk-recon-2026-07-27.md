# P8-dart-sdk-recon — состояние Dart-стороны + RAG-вердикт по пинам `D15` (2026-07-27)

> Слой 1 (сырьё) прохода дизайна 3/3, шаг 3. Всё ниже получено прогоном команд и запросами к
> первоисточникам В ЭТОМ ЖЕ ходе; вердикты по внешним фактам несут URL и дату. Потребитель —
> синтез `../research/07-p8-dart-sdk-2026-08.md` и items фазы P8.
> Репозиторий артефактов — `~/projects/packages/agentools` на коммите `4344265` (`git log --oneline -1`).

---

## 1. Что физически есть на Dart-стороне

```console
$ ls -la dart/
README.md          # 430 байт — ЕДИНСТВЕННЫЙ файл каталога
```

`dart/README.md` — плейсхолдер, и он указывает на ПРЕЖНЮЮ нумерацию фаз:

```text
Dart SDK — pub-пакет `agentools`, pure Dart ≥3.7 (без Flutter-зависимостей).
...
Реализация — фаза P3 плана `plans/AGENTOOLS` в vault `academici/brain`.
До фазы P3 этот README — placeholder.
```

«Фаза P3» здесь — метка `DR-01` §K, а не фаза плана: по карте переименования (`plan.md` §4)
Dart SDK — это фаза **P8**. Холодный старт прочитал бы устаревший ориентир — тот же дефект, что
разведка фазы P7 нашла в `php/README.md`, а разведка фазы P6 — в `conformance/README.md`.

Порог «≥3.7» в плейсхолдере **ничем не обоснован** (в решениях плана его нет) — item P8.1 обязан
либо подтвердить его источником, либо назначить порог заново с обоснованием.

Существующий Dart-код в репозитории — только песочница bakeoff, НЕ библиотека:
`bakeoff/dart/manual/*.dart` (3 ручных DTO, контрольная точка D32) и
`bakeoff/dart-transport/**` (smoke-прототип транспорта, P2.4). Оба каталога — артефакты
измерения, не переиспользуемый код.

## 2. Правила игнорирования — ловушка, ЗЕРКАЛЬНАЯ PHP-стороне

Корневой `.gitignore` (прогон `cat .gitignore`):

```text
/php/vendor/
/php/composer.lock
/dart/.dart_tool/
/dart/.packages
/dart/pubspec.lock
/conformance/vendor/
/conformance/composer.lock
/out/
*.log
.DS_Store
```

Правила `/dart/...` **якорны от корня репозитория**: они матчат РОВНО `dart/pubspec.lock` и
`dart/.dart_tool/`, но не вложенные каталоги. Прогон на планируемой раскладке (два пакета
сиблингами — см. `research/07-…` §6.1):

```console
$ git check-ignore -v dart/agentools/pubspec.lock          → NOT-IGNORED
$ git check-ignore -v dart/agentools/.dart_tool/x          → NOT-IGNORED
$ git check-ignore -v dart/agentools_mcp_dart/pubspec.lock → NOT-IGNORED
$ git check-ignore -v dart/pubspec.lock                    → .gitignore:5:/dart/pubspec.lock
```

Следствия, каждое проверяемое:

| Факт | Следствие для item'а P8.1 |
|:--|:--|
| `dart/agentools/pubspec.lock` НЕ игнорируется | переисключение (`!pubspec.lock`) на Dart-стороне НЕ нужно — в отличие от PHP (D80). Достаточно закоммитить |
| `dart/agentools/.dart_tool/` НЕ игнорируется | **обратная ловушка PHP-стороны**: без вложенного `dart/.gitignore` артефакты сборки уедут в item-коммит. Правило обязано быть заведено И доказано |
| правило `/dart/pubspec.lock` становится МЁРТВЫМ при переезде пакета в `dart/agentools/` | корневой `.gitignore` НЕ правится (правило фазы P7, D80): факт называется в Completion Notes, носитель — вложенный `dart/.gitignore` |

Правило `*.log` (строка 9) — **отдельная ловушка для доказательств F7**:

```console
$ git check-ignore -v dart/agentools_mcp_dart/evidence/resulttype-negative.log
.gitignore:9:*.log	dart/agentools_mcp_dart/evidence/resulttype-negative.log
```

D52 требует **закоммитить лог отрицательного прогона** как свидетельство. Без вложенного
переисключения `git add` этого файла — молчаливый no-op, и item закроется «с доказательством»,
которого нет в дереве. Проверенный в этом репозитории образец переисключения — `bakeoff/.gitignore`
(`!results/*.log`, `!dart-transport/*.log`), механика D34/D67.

## 3. Тулчейн исполнителя

```console
$ dart --version
Dart SDK version: 3.12.2 (stable) (Tue Jun 9 01:11:39 2026 -0700) on "linux_x64"
$ which dart    → /usr/bin/dart
$ which flutter → /home/vostrikov/soft/flutter/bin/flutter   # Flutter установлен, но пакет — pure Dart
```

Тот же класс риска, что на PHP-стороне (D77): локальный интерпретатор 3.12.2 сделает
незаметной отмену объявленного порога SDK, если констрейнт зависимости потребует свежего Dart.

## 4. RAG-вердикт по пинам `D15`/`R-08`/`R-09` на 2026-07-27

| Пакет | Роль | Пин `D15` | Факт на 2026-07-27 | Лицензия | Вердикт |
|:--|:--|:--|:--|:--|:--|
| `json_schema` | валидация 2020-12 | 5.2.2 | **5.2.2** — последняя, опубликована ≈9 мес. назад, издатель workiva.com (verified) | **BSL-1.0** | **подтверждён**, с двумя оговорками ниже |
| `ulid` | идентификаторы | 2.0.1 | **2.0.1** — последняя, опубликована ≈22 мес. назад, издатель agilord.com (verified), min Dart SDK 3.0 | **BSD-3-Clause** | **подтверждён С НАЗВАННЫМ РИСКОМ** (22 месяца без релизов) |
| JCS (RFC 8785) | канонизация | «писать самим» | готового пакета по-прежнему НЕТ (§7) | — | **подтверждён фактом**, а не отсутствием поиска |
| `mcp_dart` | транспорт (D39) | 2.3.0-dev.x | stable **2.2.2**, prerelease **2.3.0-dev.3** (§5) | MIT | **T-1 ветка (a) НЕ выполнена** |
| `test` | dev, тесты | — (D15 не касался) | **1.31.2**, опубликована ≈30 дн. назад, dart.dev | BSD-3-Clause | назначается этим заходом |
| `lints` | dev, статанализ | — (D15 не касался) | **6.1.0**, опубликована ≈5 мес. назад, dart.dev | BSD-3-Clause | назначается этим заходом |

Источники (все проверены В ЭТОМ ходе):
`https://pub.dev/packages/json_schema` · `https://pub.dev/packages/json_schema/license` ·
`https://pub.dev/packages/json_schema/changelog` · `https://pub.dev/packages/ulid` ·
`https://pub.dev/packages/mcp_dart` · `https://pub.dev/packages/mcp_dart/versions` ·
`https://pub.dev/packages/test` · `https://pub.dev/packages/lints` ·
`https://pub.dev/packages/canonical_json`.

**Оговорка 1 — карточка pub.dev показывает лицензию `json_schema` как `unknown`.** Реальная
лицензия видна только на вкладке `/license` и это **Boost Software License 1.0** (плюс материал
JSON-Schema-Test-Suite под MIT). То есть `R-09` (BSL-1.0) верен, но автодетект pub.dev его не
распознаёт — любой будущий автоматический аудит лицензий даст здесь ложное «неизвестно», и это
надо знать заранее, а не выяснять в момент публикации.
[src: `https://pub.dev/packages/json_schema/license`, 2026-07-27]

**Оговорка 2 — 2020-12 у `json_schema` НЕ является умолчанием.** Поддержка добавлена в 4.0.0
(«Support for JSON Schema Draft 2020-12 (`SchemaVersion.draft2020_12`)»), но дефолт схемы —
draft-7: версия обязана указываться ЯВНО при загрузке каждой схемы. Молчаливое умолчание — это
валидация ревизии `2026-08` по чужому драфту при зелёном прогоне.
[src: `https://pub.dev/packages/json_schema/changelog`, 2026-07-27]

**Риск `ulid` назван, а не проигнорирован:** 22 месяца без релизов при верифицированном издателе —
не «заброшен», но и не активная линия. Гасится тем же приёмом, что риск JCS-пина на PHP-стороне
(D77): поверхность использования минимальна (генерация и разбор идентификатора), поэтому подмена
пакета — правка одного файла, если фасад соблюдён.

## 5. Триггер T-1 (`DR-02` §7): stable-релиза с поддержкой modern-эры НЕТ — и причина СТРУКТУРНАЯ

Внешний факт (pub.dev, 2026-07-27): у `mcp_dart` 46 версий; последний **stable — 2.2.2**
(≈30 дн. назад), последний **prerelease — 2.3.0-dev.3** (≈4 дн. назад, линия dev.0…dev.3 активна).
README stable-линии заявляет протокол `2025-11-25` с обратной совместимостью до `2024-10-07`;
ни `2026-07-28`, ни `require2026` в нём не упоминаются.
[src: `https://pub.dev/packages/mcp_dart`, `https://pub.dev/packages/mcp_dart/versions`, 2026-07-27]

Проверка по ИСХОДНИКАМ обеих версий в локальном pub-кэше (прогон, а не чтение README):

```console
$ grep -rn "resultType" ~/.pub-cache/hosted/pub.dev/mcp_dart-2.2.2/lib          → 0 совпадений
$ grep -rln "require2026\|McpProtocol" ~/.pub-cache/hosted/pub.dev/mcp_dart-2.2.2/lib → 0 файлов
$ grep -rn "require2026" ~/.pub-cache/hosted/pub.dev/mcp_dart-2.3.0-dev.3/lib
  lib/src/types/json_rpc.dart:48  /// ... or [require2026] when a peer must support the MCP
  lib/src/types/json_rpc.dart:68    require2026;
  lib/src/types/json_rpc.dart:73    McpProtocol.stable || McpProtocol.require2026 => defaultProtocolVersion,
  lib/src/types/json_rpc.dart:83    McpProtocol.require2026 => statelessProtocolVersions,
  lib/src/types/json_rpc.dart:90    McpProtocol.stable || McpProtocol.require2026 => true,
```

**Вердикт T-1 на 2026-07-27: ветка (a) невыполнима, и не «пока не выпустили».** Modern-эра
(`McpProtocol.require2026`) и энфорсмент `resultType` физически ОТСУТСТВУЮТ в stable 2.2.2 —
это не отставание номера версии, а отсутствие функциональности. Переход на stable «ради
стабильности» ПОТЕРЯЛ БЫ обе возможности сразу. Остаётся ветка (b) — явно принятый риск
prerelease-API, — и она обязана быть подтверждена НА ДАТУ ИСПОЛНЕНИЯ фазы P8, а не унаследована
из этой выжимки: линия dev активна (4 дня между релизами), к моменту исполнения stable 2.3.0
может существовать.

## 6. Находка F7 (D52): энфорсмент `resultType` принадлежит БИБЛИОТЕКЕ — доказано исходником

Что утверждал `DR-02` §5 / D39: «`resultType` энфорсится клиентом (первый прогон упал на фикстуре
без поля)». Что нашёл аудит фазы P2 (F7): лога упавшего прогона в item-коммите `4344265` нет, а
единственная ВИДИМАЯ в коде проверка — рукописная, в нашем ТОНКОМ прототипе.

Обе половины перепроверены прогоном в этом ходе:

```console
$ grep -rn "resultType" bakeoff/results/*.log bakeoff/dart-transport/*.log
  (0 совпадений — лога упавшего прогона в дереве действительно НЕТ)

$ sed -n '37,40p' bakeoff/dart-transport/client-thin/main.dart
    if (result['resultType'] != 'complete') {
      throw StateError('unexpected resultType: ${result['resultType']}');
    }
  (рукописная проверка ТОНКОГО клиента — строка 38, как и говорит F7)

$ cat bakeoff/dart-transport/client-mcp-dart/main.dart
  (26 строк: McpClient + StreamableHttpClientTransport + connect + callTool —
   НИ ОДНОЙ проверки resultType в НАШЕМ коде)

$ grep -rn "must include resultType" ~/.pub-cache/hosted/pub.dev/mcp_dart-2.3.0-dev.3/lib
  lib/src/shared/protocol.dart:790:        'MCP stateless responses must include resultType',
```

**Вывод.** Утверждение D39 ВЕРНО по существу — строка диагностики принадлежит библиотеке
(`mcp_dart-2.3.0-dev.3/lib/src/shared/protocol.dart:790`), а в нашем `client-mcp-dart/main.dart`
проверки нет вовсе, значит наблюдённый в P2.4 отказ мог прийти только из библиотеки. Но КЛАСС
доказательства был неверным: заявлено «эмпирически подтверждено прогоном», а прогон не оставил
следа в дереве. Здесь он заменён на воспроизводимый grep по исходнику пинованной версии.

Это НЕ закрывает D52: тот требует **закоммиченный лог отрицательного прогона** (фикстура без
`resultType`), то есть свидетельство класса `real` на дереве фазы P8, а не пересчёт на машине
дизайнера. Что заход даёт item'у P8.12 — точный адрес, по которому он найдёт энфорсмент, и
предупреждение §2 о том, что лог без переисключения `*.log` не закоммитится.

Отдельное следствие для T-1: энфорсмент `resultType` живёт в ТОЙ ЖЕ prerelease-линии, что и
`require2026` (§5). То есть «уйти на stable» = потерять и то, и другое; ветки T-1 не независимы.

## 7. JCS в Dart: готового пакета нет — и ближайший кандидат ПРОТИВОРЕЧИТ §D4

`D15` предписал «Dart — писать самим». Проверено, что это по-прежнему так, и что ближайший
похожий пакет не годится не по зрелости, а по СЕМАНТИКЕ:

`canonical_json` 1.1.3 (издатель google.dev, Apache-2.0) реализует **Canonical JSON проекта OLPC**,
а не RFC 8785, и прямо декларирует правила: целые без ведущих нулей, **числа с плавающей точкой
ЗАПРЕЩЕНЫ**, ключи сортируются по байтам, пробелы запрещены, из escape-последовательностей
разрешены только `\\` и `\"`, строки обязаны быть в **Unicode Normalization Form C**.
[src: `https://pub.dev/packages/canonical_json`, 2026-07-27]

Два из этих правил — прямое противоречие `DR-01` §D4:

| Требование `DR-01` §D4 | `canonical_json` 1.1.3 |
|:--|:--|
| ECMAScript-совместимая сериализация ЧИСЕЛ (включая дробные) | числа с плавающей точкой **запрещены** |
| **никакой** скрытой Unicode-нормализации | **требует** NFC |

Реестр реализаций JCS в `cyberphone/json-canonicalization` Dart среди языков не перечисляет.
Вывод: пин `D15` «писать самим» подтверждён положительным фактом (кандидат разобран и отклонён по
названным правилам), а не отсутствием находок. Отдельное следствие для item'а P8.3: NFC — это не
абстрактный риск, а РЕАЛЬНОЕ поведение соседнего пакета; собственная реализация обязана доказать
его отсутствие тестом, а не комментарием.

## 8. Что этот заход НЕ проверял (границы честности)

- Ревизия `2026-08`, её схемы и корпус `fixtures/2026-08/**` НЕ существуют на дату разведки
  (`ls spec/revisions/` → только `2026-07-draft`; `ls fixtures/` → `golden`, `schema`, `vectors`,
  `README.md` прежней ревизии). Все ссылки items фазы P8 на них — на артефакты фаз P3/P4.
- `conformance/` содержит только `README.md`: раннера, реестра `sdks.json`, `bin/lockstep`,
  `bin/levels` и `tools/ci_gate.py` НЕТ — их производит фаза P6, и фаза P8 их ЧИТАЕТ, а не заводит.
- Ни один пакет фазы P8 не собирался: `dart pub get` не запускался, разрешимость констрейнтов
  вместе (`json_schema` + `ulid` + `test` + `lints` на одном SDK-пороге) НЕ проверена прогоном —
  это обязанность item'а P8.1 на дату исполнения.
- Прогноз выхода stable `mcp_dart` 2.3.0 не делался: линия активна, но дата релиза внешнему
  наблюдателю не известна (это же — остаток Q-7 в части зрелости расширения задач).
