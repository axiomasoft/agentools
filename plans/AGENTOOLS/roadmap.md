# Roadmap исполнения — AGENTOOLS

**Обновлён:** 2026-08-05 · **Соответствует plan.md:** v0.41.1 · **Следующий шаг:** `task:plan-run AGENTOOLS P6.3` — реализовать reference adapter stages core 121 → extension 92 → full 213 на terminal admission bundle. Репозиторная копия — исполнимый SSOT этой сессии.

Карта отвечает на «какую команду дальше?» без разбора всего плана. Она **не переопределяет**
`plan.md` §3 Routing (модели и effort — оттуда), а только группирует запуски и называет гейты
владельца. Живой документ: закрытие фазы либо re-design обновляет таблицу + строку Update Log.

**Три правила чтения.** (1) `Batch` — последовательные items ОДНОГО маршрута, закрываемые одной
сессией; закрытие КАЖДОГО item'а идёт полным протоколом `plan-protocol` §8 (item-коммит +
bookkeeping + handoff), батч закрытия не сливает. (2) `solo` — собственная сессия; в колонке
«Примечание» обязано стоять, почему нельзя слить. (3) `workflow` — независимые по scope items
одним скриптом; parallel authors НЕ коммитят и НЕ закрывают items, после join общий carrier
последовательно валидирует и закрывает их предписанным маршрутом (D55/D154).

**Носитель членства в группе — `plan.md` §3 Routing, колонка `Batch`** (D122): карта её ПОКАЗЫВАЕТ,
но не назначает — как и модели с effort. Расхождение колонки `Batch` этой карты с §3 = дефект карты,
чинится картой. Критерий, по которому `full`-item попадает в батч (B1–B7) либо остаётся solo
(P7–P10), назван одной записью — D122 (уточнена D127: условие «предмет ещё не опубликован»
временно́е — строка `Exported` до freeze батч не запрещает; гейт условия (2) обязан быть
внутрифазовым и не членом группы, поэтому B1 — объявленное исключение с ценой, а P5.1 —
внефазовый контур); «`Review=full`» сам по себе батчинг не запрещает и не разрешает.

**Порядок фаз.** P1 и P2 независимы (D21) — P2 закрыта. Дальше строго последовательно:
P3 гейтится P1, P4 гейтится P3, P5 гейтится P3+P4; P6–P11 — GREEN P5.1, baseline P5.3 и freeze P5.2.
**Remediation-order после Gate B round 1 и трёх RED-аудитов P3 (D141–D152):** P3.16 → owner-gates
P3.17/P3.18 → P3.19/A2 → RED-аудит → P3.20 → P3.21 → P3.22 → P3.23/A2 → close/audit P3 → P3.24 → RED/AUDIT-CAP →
P4.14 fix-forward (`51fc06f`, закрыт) → P4.9 (`ef80fcd`, закрыт) → workflow W2
(P4.10/P4.11/P4.12, закрыт) → адресный re-open P4.10 D158 (`ea0a08f`, закрыт) →
greenfield re-open P4.10 D160 → P4.13/A3 → close P4 → P5.1 round 2 RED → owner D161 →
P4.16 → P4.17 → P4.18 → P4.19 → P4.20 → P4.21 → close P4 → Gate B round 3 GREEN (`f810d62`) → P5.3. Старый W1 и
P4.1–P4.8 терминальны и повторно не запускаются.

---

## Карта исполнения

| Item | Batch | Запуск | Model class/Effort | Гейт владельца | Примечание |
|:--|:--|:--|:--|:--|:--|
| P0.1–P0.11 | — | — | — | — | **фаза закрыта** 2026-07-10, аудит GREEN 2026-07-11 (7/11 🟢, все терминальны) |
| P1.1 | B1 | `plan-exec` серия | implementation/medium | — | нужна сеть (`perplexity-web`/`WebFetch`) — без неё item не исполняется, `🔴` (§3 «Почему») |
| P1.2 | B1 | ↑ | implementation/medium | — | `Inputs` ← Deliverables P1.1: порядок внутри сессии жёсткий |
| P1.3 | B1 | ↑ | implementation/medium | ⚠️ при `ГЕЙТ A0: BLOCKED` | вердикт гейта A0 разблокирует фазу P3; дельта, ОТМЕНЯЮЩАЯ основание решения `DR-01`, → эскалация, а не ре-дизайн |
| P2.1–P2.5 | — · `B-A1` (P2.2–P2.4) | — | — | — | **фаза закрыта** `🟠` (4 🟢 + P2.5 🟠), аудит `ATTENTION`; находки → P3.15, P4.6, P8.12, D48/D49. Колонка `Batch` повторяет §3 построчно: P2.1 и P2.5 — solo, P2.2–P2.4 закрыты батчем `B-A1` (исторический идентификатор фазы, к нумерации B1–B7 волны A/B не относится) |
| P3.15 | solo | `plan-exec` | implementation/low | — | **запускать ПЕРВЫМ в фазе**: снимает единственный красный `plan-lint` плана (P2.1 без строки `write-site:`) и не зависит ни от одного item'а авторинга; solo — другой маршрут (`implementation/low`), в батч с frontier-авторингом не сливается |
| P3.1 | solo | `plan-run` | implementation/high | — | каркас ревизии + профиль линтера + closer — ВХОД всех двенадцати items авторинга; ошибка каркаса переписывает 12 документов, поэтому отдельная сессия с собственным ревью |
| P3.2 | solo | `plan-run` | frontier/high | — | `00_overview.md` — глоссарий и уровни, которые читают ВСЕ остальные документы (D47); слияние с последующими дало бы терминологию, зафиксированную в том же ходе, где её и вводят |
| P3.3, P3.4 | B2 | `plan-run` серия | frontier/high | — | идентичность расширения → профиль инструмента: один предмет (грамматика вендорного ключа и дескриптор), `02` прямо ссылается на `01` |
| P3.5, P3.6 | B3 | `plan-run` серия | frontier/high | — | жизненный цикл действия → подтверждение: токен привязан к действию журнала, `04` ссылается на `03` |
| P3.7, P3.8 | B4 | `plan-run` серия | frontier/high | — | уточнения (MRTR) → сценарии: шаг сценария порождает уточнение, грамматика читает семантику `05` |
| P3.9, P3.10 | B5 | `plan-run` серия | frontier/high | — | длинные операции → аудит и наблюдаемость: `08` нормирует события, которые порождает `07` |
| P3.11, P3.12 | B6 | `plan-run` серия | frontier/high | — | идемпотентность/ошибки → безопасность: коды и принципалы разбираются вместе |
| P3.13 | solo | `plan-run` | frontier/high | — | `11_conformance-harness.md` нормирует ВСЁ, что реализует фаза P6 (харнесс, severity, baseline, CI, golden **включая способ доставки пары** — D117, lockstep); объём и цена ошибки не допускают соседа в сессии |
| P3.14 | solo | `plan-run` | implementation/high | — | генератор `rules.json` + сводный `ГЕЙТ A2` — гейт-item фазы, читает все двенадцать документов; маршрут отличается от батчей авторинга |
| P3.16 | solo | `plan-run` | implementation/high | — | первый prerequisite: отдельный SEC-3 permission-код; собственная сессия из-за единственного public error dictionary |
| P3.17 | solo | `plan-run` | **frontier/high** | ✅ **Q-5** | namespace inventory 32/35 + owner-ветка keep/migrate; без D# не стартует |
| P3.18 | solo | `plan-run` | **frontier/high** | ✅ **Q-27** | measurable actionable-description либо explicit defer; без D# не стартует |
| P3.19 | solo | `plan-run` | implementation/high | — | терминальный повтор A2 после трёх remediation-items; запускается последним в реопененной P3 |
| P3.20 | solo | `plan-run` | implementation/high | — | чинит пересечение `oneOf` в `ResultType`/`StatusChannel`/`TaskStatus` и вводит общий schema-values selftest |
| P3.21 | solo | `plan-run` | implementation/high | — | синхронизирует lifecycle markdown с `EventKind`; solo из-за общего `tools/spec_lint.py` с P3.20 |
| P3.22 | solo | `plan-run` | implementation/high | — | source + `rules.json`: `CFH-55`, шесть терминов OVR-25, exact gap `[CFH-55 → P4.12]`; fixtures не трогает |
| P3.23 | solo | `plan-run` | implementation/high | — | terminal authoring A2: schema-values evidence F1–F5 + честный coverage debt D149, без full-coverage claim |
| P3.24 | solo | `plan-run` | implementation/high | — | fail-closed schema-values checker: полный shape + Draft 2020-12 oracle + две обязательные мутации; нормативные schemas не меняет |
| P3.25 | — | — | — | D165 | ⛔ skipped: compiler `LOOP_BUDGET_EXCEEDED`; owner truth уже несут D164/Q-28/LOP-26 |
| P4.1 | — | — | — | — | **закрыт**; формат корпуса уже заморожен |
| P4.6 | — | — | — | — | **закрыт**; must-ignore калиброван фактом |
| P4.2 | — | — | — | — | **закрыт**; повторный запуск W1 не должен перезаписывать его артефакты |
| P4.3 | — | — | — | — | **закрыт**; JCS/idempotency-корпус и golden-пары зафиксированы |
| P4.4 | — | — | — | — | **закрыт** `4d86009`; общий вход D139/D140 готов, artifact-приговоры — P4.5 |
| P4.7 | — | — | — | — | **закрыт**; старый W1 повторно не запускается |
| P4.8 | — | — | — | — | **закрыт**; старый W1 повторно не запускается |
| P4.5 | — | — | — | — | **закрыт исторически**; формальный A3 не равен Gate B GREEN |
| P4.14 | — | — | — | — | **закрыт** `51fc06f`; exact registry/full-definition gate устранил AUDIT-CAP debt F1/F2 до P4.9 |
| P4.9 | — | — | — | — | **закрыт** `ef80fcd`; driver-state и D145-valid real-byte offline control — общий input W2 |
| P4.15 | — | — | — | — | **закрыт** `8919b92`; closed policy/confirmation/audit-export inputs and selftest |
| P4.10 | — | — | — | — | **закрыт** `499fe1a`; historical relation-only evidence не является semantic GREEN |
| P4.11 | W2 | — | — | — | **закрыт** `0581400`; F5 scenario positive/negative pairs, исторический workflow повторно не запускается |
| P4.12 | W2 | — | — | — | **закрыт** `e50bb5e`; static SEC-17/AOB-26 and CFH-55 pair, исторический workflow повторно не запускается |
| P4.13 | — | — | — | — | **закрыт** `2ffff1c`; formal A3/evidence-map, недостаточность доказана Gate B round 2 |
| P4.16 | solo | `plan-run` | implementation/high | — | harness-owned observation schema + anti-self-report mutations; общий вход остальных remediation-items |
| P4.17 | solo | `plan-run` | implementation/high | — | exact 38 behavior rules F9/F11–F14; после terminal P4.16 |
| P4.18 | solo | `plan-run` | implementation/high | — | exact 13 artifact rules F9/F10/F15; после terminal P4.17, пишет общий checker |
| P4.19 | solo | `plan-run` | implementation/high | — | exact 51 semantic manifest + fresh A3; единственный вход Gate B round 3 |
| P5.1 | solo | `plan-run` | frontier/xhigh | — | адверсариальный гейт волны: вердикт обязан быть ОДНИМ и когерентным (D59/D61). **Команда — НЕ `task:plan-audit`**: её субъект — фазы плана, а здесь — артефакты ревизии |
| P5.3 | solo | `plan-run` | frontier/high | ✅ **Q-30, Q-31** | package-quality baseline и reconciliation identity/authority. Выполняется после GREEN P5.1 и ДО freeze; без ответа владельца — `🔴 Blocked` |
| — | — | — | — | ✅ **Q-5 (домен) + Q-10 (trademark)** | гейт СТОИТ МЕЖДУ items фазы (D64): нет ответа — P5.2 ждёт со строкой `Возобновление:`, ревизия остаётся `draft`; молчание ≠ «да» |
| P5.2 | solo | `plan-run` | implementation/high | ↑ | freeze — необратимая публикация: жёсткий порядок шести шагов (D62), перестановка замораживает расхождение молча |
| P6.1 | solo | `plan-run` | implementation/high | — | раннер — предмет, от которого зависят все восемь остальных items фазы; инвариант матчера и различение «отказ SUT ↔ отказ раннера» |
| P6.2 | solo | `plan-run` | frontier/high | — | admission only: exact corpus correction + oracle-safe runner + protocol 1.1 runner-owned probes + selector partition 121+92=213 + re-freeze/tag; adapter запрещён (D183) |
| P6.3 | solo | `plan-run` | implementation/high | — | потребляет только terminal P6.2 bundle; полный reference adapter stages core 121 → extension 92 → full 213; frozen input не меняет; full report передаёт P6.4 (D183) |
| P6.4, P6.5, P6.6 | B7 | `plan-run` серия | implementation/high | — | три механики одного модуля: severity/baseline → golden → harness-selftest; P6.6 исполняет CFH-55 exact/wrong-code pair D149. Батч легален по D122, терминальный P6.9 перепроверяет весь предмет; `Review=full` не подавляется. |
| P6.7 | solo | `plan-run` | implementation/high | optional input Q-12; нет ответа → (C), без паузы | исполняет три ветки D68/D182; только ЯВНО выбранная (B) блокирует из-за новой dated revision — молчание владельца не останавливает item |
| P6.8 | solo | `plan-run` | implementation/high | — | lockstep при НУЛЕ существующих SDK доказывается отрицательными прогонами на фикстурах; здесь же фиксируется форма объявления ревизии, которую читают P7.1 и P8.1 (D81/D89) |
| P6.9 | solo | `plan-run` | implementation/high | — | гейт-item фазы: пара прогонов (зелёный + мутант) + отчёт по уровням с обязательным `--out` (D118); запускается ПОСЛЕДНИМ |
| P7.1 | solo | reconciliation pending | implementation/medium | — | `🟡` F16 anchor `b11cf41`; повторная реализация запрещена, terminal closure после восстановления provenance/validation |
| P7.2 … P7.10 | solo ×9 | reconciliation pending | implementation/high | — | `🟡` F16 anchors `c690a67`…`e232fc5`; current-tree PHPUnit 65/65 и PHPStan green, route прежних исполнителей не выдумывается (D163) |
| P7.11 | solo | `plan-run` | implementation/high | — | ещё не начат; старт только после штатного phase-first gate и reconciliation P7.1–P7.10 |
| P7.12 | solo | `plan-run` | implementation/high | ⚠️ ветка (C) `DR-02` §6 | гейт-item фазы: прогон против замороженного корпуса + приземление исхода owner-gate + ВЫЧИСЛЕНИЕ уровня по порогам D7 (заявление выше измеренного — провал гейта, D82) |
| P8.1 | solo | `plan-exec` | implementation/medium | — | скелет ядра, жёсткий routing-гейт; форма объявления ревизии берётся у читателя `bin/lockstep` — её отсутствие даёт `🔴` с адресатом P6.8 (D89) |
| P8.2 … P8.11 | solo ×10 | `plan-run` | implementation/high | — | **батчинг ЗАПРЕЩЁН D96** (та же причина, что D84; критерий — D122: пять межъязыковых якорей раннер не видит по построению, D98) + запрет транслитерации PHP (D97): расхождение объявляется дефектом только после прогона того же вектора против ЭТАЛОНА и адаптера PHP |
| P8.12 | solo | `plan-run` | **frontier/high** | ⚠️ ветка (c) триггера T-1 | ЕДИНСТВЕННЫЙ item фазы с открытыми решениями: вердикт T-1 на дату исполнения, исход находки F7, публичная поверхность ВТОРОГО pub-пакета (D95) |
| P8.13 | solo | `plan-run` | implementation/high | — | гейт-item: ПЯТЬ межъязыковых якорей (D98) — несовпадение любого = `BLOCKED` независимо от зелёного корпуса; ВОСЕМЬ отрицательных доказательств |
| P9.1 | solo | `plan-run` | implementation/high | — | датированное измерение чужого контура + Q-15 предметно (пересечение «правила среза × красные векторы `ГЕЙТ P7`», D107) + сбор пакета решения |
| — | — | — | — | ✅ **Q-18: дом кода среза** | ветку (A/B/C) выбирает ВЛАДЕЛЕЦ по пакету P9.1 (D102); выводить её из молчания плана `FLEX-2` ЗАПРЕЩЕНО. Блокирует P9.3–P9.7 |
| P9.2 | solo | `plan-run` | **frontier/high** | ✅ **Q-19 внутри контракта** | INT-001: развод ТРЁХ гейтов подтверждения в один механизм (D100), реанкор `B-32` П1–П7 (D101), размещение журнала (D103); открытые решения + необратимая цена |
| P9.3 … P9.6 | solo ×4 | `plan-run` | implementation/high | — | **батчинг ЗАПРЕЩЁН D105, критерий — D122**: каждый пишет в ЧУЖУЮ кодовую базу с тенантной изоляцией и ПДн, а гейт-item P9.7 (E2E) порядок middleware и односторонний permission-гейт не измеряет ⇒ `Review=full` без замены гейтом; минимум `plan-exec` недостаточен |
| P9.7 | solo | `plan-run` | implementation/high | — | гейт-item: E2E с РЕАЛЬНОЙ моделью и интерпретацией её ошибок + отчёт среза; отбор удачных прогонов запрещён |
| P10.1 | solo | `plan-run` | implementation/high | — | re-сверка ДВУХ репозиториев и ДВУХ чужих статус-бордов + Q-17 предметно (D109); законный исход сверки — `waiting:` |
| — | — | — | — | ✅ **Q-20: дом кода переноса** | ветки (A)/(B)/(C1 `waiting:`)/(C2 демо-стенд вне контура) — выбор владельца по пакету P10.1 (D109/D110). Блокирует P10.3–P10.5, но **НЕ** P10.6 |
| P10.2 | solo | `plan-run` | **frontier/high** | ✅ **Q-21, Q-22, Q-23** | INT-002: cross-plan gate-таблица против ДВУХ бордов (D112), реконсиляция R1–R10 (D111), предложения дельт ЧУЖОГО замороженного канона |
| P10.3 … P10.5 | solo ×3 | `plan-run` | implementation/high | — | **батчинг ЗАПРЕЩЁН D115, критерий — D122** (чужая кодовая база + чужой замороженный канон, гейт-item P10.6 — аналитический отчёт, а не машинная перепроверка предмета); старт зависит от чужой цепочки вех (`ERP-IMPL` P1 `⬜` 0/10) — законный исход `waiting:` |
| P10.6 | solo | `plan-run` | **frontier/high** | — | **гейт-item фазы И ВЫХОД ВСЕГО ПЛАНА**; по D113 НЕ гейтится терминальностью P10.3–P10.5 — аналитическая часть выпускается независимо, а отсутствие эмпирики печатается разделом «на чём стоит вердикт». Нужны `perplexity-web`/`WebFetch`/`context7` |
| P11.1 | solo | `plan-run` | implementation/high | ✅ **Q-30** | единая legal/publication identity во всех публичных носителях |
| P11.2 | solo | `plan-run` | implementation/high | — | public API manifests, docs и examples; phase/reference leakage запрещён |
| P11.3 | solo | `plan-run` | implementation/high | — | clean-checkout quality gate через единственный `tools/ci_gate.py` |
| P11.4 | solo | `plan-run` | implementation/high | ✅ публикация только по owner decision | release candidate + независимый PHP/Dart consumer smoke |

**Итого:** 105 items в фазах P1–P11. Точный живой счёт статусов — только `plan.md` §4; эта карта
фиксирует порядок. Историческая раскладка: 7 групп `Batch` (16 items) и W1 (4 items).
Текущая remediation добавляет 17 solo-items (исторические P3/P4 repair + P3.25/P4.16–P4.19) и workflow W2
(P4.10–P4.12). Owner-гейты Q-5/Q-27 закрыты P3.17/P3.18; второй реопен owner-гейтов не добавляет.

---

## Готовые launch-block'и групп

### P4.16 — observational contract D162

**Next:** «ЗАПУСК ВРУЧНУЮ: `implementation/high`»

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high — observational ownership gate |
| Capabilities | — |
| Context | continue (reset the session context) — ручной item |
| Суть | Ввести harness-owned observation schema и anti-self-report mutation selftest. |
| Команда | — |

```text
# select the session model class implementation
# select the session effort high
$ task:plan-run AGENTOOLS P4.16
```

### B1 — фаза P1 (A0): пин финала MCP, дельта-таблица, реконсиляция `DR-01`

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium — сверка внешнего факта по сети, открытых design-решений нет |
| Capabilities | `perplexity-web` / `WebFetch` — **обязательны**: без сети item P1.1 не исполняется (`🔴` + эскалация), «по памяти» заполнять запрещено |
| Context | cold-start: `plan.md` §1–§3 → `roadmap.md` → `phases/P1.md` |
| Суть | Три item'а фазы A0 одной сессией по порядку; финал — вердикт `ГЕЙТ A0`, разблокирующий фазу P3 |

```
exec-items: task:plan-exec AGENTOOLS P1.1 P1.2 P1.3
```

<!-- B1 — ОБЪЯВЛЕННОЕ ИСКЛЮЧЕНИЕ критерия D122 (D127): условие (2) требует гейта, не входящего
     в группу, а `ГЕЙТ A0` живёт внутри P1.3 — группа подтверждает сама себя. Цена принята и
     названа: предмет воспроизводим повторным запросом (коммит + sha256), а вердикт гейта до
     freeze перепрогоняется линзой 5 аудита P5.1 на закоммиченном дереве. Ревью каждого item'а
     батчем НЕ подавляется — закрытие идёт полным протоколом §8 по каждому. -->

<!-- B2–B6 (ниже) проходят критерий БЕЗ исключения: внутрифазовый терминальный гейт — P3.14,
     он не член ни одной группы. Гейт волны B (P5.1) стоит в ДРУГОЙ фазе — это внефазовый
     второй контур, за условие (2) он не засчитывается. -->


### P3.15 — гигиена бухгалтерии фазы P2 (запускать первым в фазе P3)

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | low — правки точечные и перечислены поимённо, новых решений нет |
| Capabilities | — |
| Context | cold-start: `phases/P2.md` блок `## Audit P2 — 2026-07-27` → `phases/P3.md` P3.15 |
| Суть | Снять единственный красный `plan-lint` плана и невоспроизводимые числа в закрытых Completion Notes; фаза P2 НЕ реопенится (D50) |

```
exec-items: task:plan-exec AGENTOOLS P3.15
```

### B2–B6 — авторинг документов ревизии `2026-08` (пять парных серий)

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high — нормативный авторинг с открытыми формулировками; цена ошибки замораживается гейтом фазы P5 |
| Capabilities | — (внешние факты уже пиннуты фазой P1) |
| Context | cold-start: `phases/P3.md` Phase Context → `research/02-p3-authoring-conventions-2026-08.md` → items серии |
| Суть | Пара смежных документов одной сессией; каждый item закрывается полным протоколом §8 отдельно |

```
run-items: task:plan-run AGENTOOLS P3.3-P3.4
```

<!-- Остальные серии — той же формой: P3.5-P3.6 (B3) · P3.7-P3.8 (B4) · P3.9-P3.10 (B5) ·
     P3.11-P3.12 (B6). Сессия ОБЯЗАНА быть frontier/high: `plan-run` работает в режиме
     inherited-plan и сверяет ФАКТИЧЕСКИЙ маршрут сессии с §3 Routing; undershoot →
     ROUTING-BLOCKED. Solo-items фазы (P3.1, P3.2, P3.13, P3.14) — той же командой без диапазона. -->

### W1 — волна авторинга корпуса векторов (история; повторный запуск запрещён)

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high — авторинг данных контракта против замороженных правил; ложная ссылка на правило проходит машинный гейт |
| Capabilities | `command.subagents`, `command.workflow-carrier` |
| Context | cold-start — orchestration: `plan.md` §3 → `roadmap.md` → `phases/P4.md` → `research/03-p4-vector-corpus-2026-08.md` |
| Суть | Исторический carrier P4.2/P4.3/P4.7/P4.8; все четыре terminal, remediation использует W2. |

```
# ИСТОРИЯ — НЕ ЗАПУСКАТЬ ПОВТОРНО
plans/AGENTOOLS/workflows/wf-agentools-p4-vectors.js
```

<!-- Предусловие запуска: закрыты P4.1 (формат вектора и схемы фикстур заморожены), P4.6
     (must-ignore калиброван фактом) и solo P4.4 (D139, `02`/`rules.json` — общий вход P4.7/P4.8).
     Порядок — `phases/P4.md` §«Порядок исполнения»; та же формулировка в `index.md` строке
     `workflows/`. Прежний скрипт `wf-agentools-drive.js` УСТАРЕЛ, запуск запрещён (D23). -->

### B7 — механики раннера одного модуля (фаза P6)

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high — инварианты выражаются КОДОМ, а не дисциплиной (запрет тихого SKIP, вторичный байтовый сигнал) |
| Capabilities | — |
| Context | cold-start: `phases/P6.md` Phase Context → `research/05-p6-conformance-harness-2026-08.md` §7 → items серии |
| Суть | Severity/baseline → golden → самотест harness: три механики одного модуля `conformance/src/**` с общим прогоном тестов; `Review=full` наследуется всем батчем |

```
run-items: task:plan-run AGENTOOLS P6.4-P6.6
```

### Solo-цепочки фаз P7–P10 (38 items) и solo-items фаз P3–P6 (20)

Общая форма; маршрут каждого item'а — из `plan.md` §3 построчно, **сессия обязана ему
соответствовать** (`plan-run` инвертирует routing-гейт и сверяет фактический маршрут):

```
run-items: task:plan-run AGENTOOLS <Pn.m>
```

Исключения — два item'а с `Exec = plan-exec` (жёсткий гейт тира, `implementation/medium`):

```
exec-items: task:plan-exec AGENTOOLS P7.1
```

---

## Гейты владельца (сводно)

| Где | Что утверждает владелец | Блокирует |
|:--|:--|:--|
| после P3.16, до P3.19 | **Q-5:** keep/migrate namespace по inventory 32/35 · **Q-27:** measurable actionable-description либо explicit defer | P3.17/P3.18 → P3.19 → вся remediation P4; нет D# ⇒ freeze/P6 также запрещены (D143) |
| **до P5.2** (рекоменд. — до старта P6) | **Q-12:** исход owner-gate `DR-02` §6 — слой 2 четырёхслойного CI на стороне SDK | ветку исполнения P6.7, P7.12, P8.13. **Цена промедления названа:** до freeze ветка (B) стоит одной строки Списка A и правки фазы P3, после freeze — перевыпуска ревизии целиком (D68) |
| между P5.1 и P5.2 | **P5.3**, Q-30 (legal/publication identity), Q-31 (authority DR-01), затем Q-10 | baseline и owner-решения блокируют freeze и старт P6–P11; Q-5 уже должен быть закрыт P3.17 |
| после P9.1 | **Q-18:** дом кода вертикального среза — ветка (A) зависимость `corex/ai` на SDK · (B) тонкий мост · (C) пакет не создан | P9.3–P9.7 (D102) |
| внутри P9.2 | **Q-19:** размещение журнала действия относительно канонных `ai_*` — ветка (a) или (b) | P9.4 (D103) |
| после P10.1 | **Q-20:** дом кода переноса — (A)/(B)/(C1 `waiting:`)/(C2 демонстрационный стенд вне контура) | P10.3–P10.5; **не блокирует P10.6** (D113) |
| внутри P10.2 | **Q-21** (журнал против `ai_*` контура ERP) · **Q-22** (владение движком сценариев — Rule of Three) · **Q-23** (пин `mcp_dart` контура против эры ревизии) | P10.3–P10.5 (D111, D112) |
| вне фаз | **Q-2** (remote репозитория) · **Q-4** (канон-папка продукта) · **Q-8** (стратегия релиза ДВУХ pub-пакетов) · **Q-26** (голосовой канал и голосовое подтверждение — вне предмета ревизии, D126) | ничего в плане; Q-2 честно ограничивает гейт CI локальным прогоном (D69) |
| вне плана (продукт / юр.) | **Q-1** (кадровый домен — владелец продукта AiFina/AiResto, D114) · **Q-25** (юридический sign-off 152-ФЗ на облачные LLM — ревизия LLM не вызывает, D126) | ничего в плане; обе — честные границы, а не отложенные решения |
| вне плана (скилл) | **Q-24:** закрытый enum `Next` не покрывает режимы `finish`/`roadmap` — адресат владелец скилла `general/plan-protocol` | ничего; обход — форма `manual` |

---

## Что карта сознательно НЕ обещает

- **Даты.** Ни один item не несёт срока: старт кодовых items фаз P9 и P10 зависит от ЧУЖИХ
  цепочек вех (`FLEX-2` item `P6.15`; `ERP-IMPL` P1 `⬜` 0/10 → критпуть → строки `MCP`/`AS`
  борда `erp` §8), которыми этот план не владеет и статус которых не вправе читать как
  разрешение. Законный исход сверки — `waiting:` (D115).
- **Число сессий как оценку трудоёмкости.** 58 solo — это не «58 равных шагов»: solo назначен
  критерием D122 (уточнён D127), а не размером items. У items фаз P7–P10 не выполнено ни одно
  из двух его условий: предмет группы ПУБЛИКУЕТСЯ ею же (поверхность пакетов `agentools/sdk` и
  `agentools`, запись в чужие кодовые базы контуров), а гейт-items фаз гоняют КОРПУС и класс
  дефекта публичной поверхности по построению не видят. Формулировка «`Review=full` не
  сворачивается в поток» причиной solo больше НЕ является — она была прочтением канона,
  отменённым `Consequences` D122 (те же `full`-items стоят в семи батчах B1–B7). Остальные solo —
  гейт-items фаз, items с открытыми решениями и items другого маршрута, которые в группу не идут
  по построению.
- **Что гейты владельца будут отвечены до подхода к ним.** Каждый гейт несёт исход «ответа
  нет» отдельной веткой, и ни одна из них не читает молчание как согласие.
