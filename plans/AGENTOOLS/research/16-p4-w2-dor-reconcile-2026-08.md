# 16 — P4 W2: reconcile исполнимости и exact remediation inventory

**Дата:** 2026-08-04  
**Статус:** design input повторного захода `task:plan-design AGENTOOLS P4`  
**Сырьё слоя 1:** `../artifacts/P5-gate-b.md` (round 1, F1–F8 и матрица 382 правил) ·
фактический precheck репозитория на `26220f4` · закрытые P4.14/P4.9.

## 1. Фактическая точка старта W2

P3 терминальна как `🟠 Done with deviations`: последний item P3.24 закрыт, а outer-shape долг
его третьего аудита исправлен отдельным terminal P4.14. P4.9 также terminal и передал W2
формальный driver-state. На дереве `26220f4` полный `spec_lint` имеет ровно одну ошибку —
непокрытый `CFH-55`; `--fixtures-check` зелёный; рабочее дерево чисто. Следовательно, готовность
W2 проверяется состояниями **P3 terminal + P4.14 terminal + P4.9 terminal** и exact debt
`[CFH-55]`, а не исторической точкой P3.19. `RAG:— (repo-grounded: plan.md §4; phases/P3.md
P3.24/Audit заход 3; phases/P4.md P4.14/P4.9; команды precheck 2026-08-04)`.

## 2. Reconcile headline `32 carriers` с воспроизводимым exact-set

`artifacts/P5-gate-b.md` сообщает headline «32 поимённо разобранных носителя», но не публикует
реестр из 32 идентификаторов и не задаёт онтологию счёта (fixture id, schema carrier и checker
branch смешаны в находках). Число поэтому нельзя использовать как exact-set acceptance gate:
исполнитель мог бы получить `32` только изобретением пяти неназванных строк.

Воспроизводимые множества отчёта:

- приложение матрицы содержит **116** строк `FAIL F1`…`FAIL F6` и **24** уникальных sole-carrier
  id; команда: `sed -n '55,470p' plans/AGENTOOLS/artifacts/P5-gate-b.md | awk -F'|' '/FAIL
  F[1-6]/{n++; gsub(/^ +| +$/, "", $4); c[$4]=1} END{print n, length(c)}'` → `116 24`;
- union матричных id со всеми fixture-id, явно названными в F1–F6, даёт **27** round-1 fixture
  carriers. Три не-sole, но всё равно вырожденных кейса — `longop-002`, `scnnocode-003`,
  `scenario-004`; они входят в remediation по самим находкам F2/F5;
- `fixtures/2026-08/schema/vector.json` и checker branches `tools/spec_lint.py` — причины
  вырождения/исполнительские механизмы, но не fixture carriers и потому живут отдельными полями
  evidence-map, не добивают счёт до headline.

Канонический exact inventory W2 — следующие 27 исходных fixture id:

```text
audit-004
cfhbaseline-001
cfhgrease-001
cfhreport-001
clarificationstate-001
confdual-001
confpolicy-001
conftoken-001
extidentity-001
extnegotiation-001
lifecycleconcurrency-001
lifecyclefold-001
lifecycleindex-001
lifecycleintegrity-001
longop-002
ovrdev-001
profileuntrusted-001
scenario-004
scnnocode-003
scnorder-002
security-001
security-002
security-003
security-004
toolauthoring-001
toolcontract-001
tooldigest-001
```

Разбиение без пересечения: P4.10 — 14 behavior id; P4.11 — 3 scenario id; P4.12 — 10 static
id. Manifest P4.13 обязан хранить `reported_carrier_count: 32` как исторический факт отчёта и
отдельно exact `round1_fixture_carriers` из 27 id, `round1_fail_rules` из 116 rule id,
`driver_schema_subject` и checker predicates. Gate применяется к точным множествам 27/116, не к
невосстановимому headline. `RAG:— (repo-grounded: artifacts/P5-gate-b.md §§Почему RED,
Линза 3, Находки F1–F6)`.

## 3. Workflow W2: безопасная граница параллелизма

Параллельны только authoring write-sets P4.10/P4.11/P4.12. P4.12 обязан владеть не только
`static/**` и `tools/spec_lint.py`, но и `schema/harness-case.json` плюс двумя harness-case
файлами `CFH-55`; иначе D149 неисполняем. Авторы не коммитят и не меняют план. Общие
`spec_lint`/coverage gates не считаются окончательными во время параллельной записи: после join
они перепроверяются последовательным closer'ом. Read-only review выполняется после join;
допускается максимум одна fix-итерация с обязательным повторным review, затем workflow
останавливается и возвращает находки main loop.

Closer исполняется тем же минимумом `implementation/high`, что и item Routing. Пин
`implementation/low` годится для механического `plan-close`, но не для проверки/коммита ещё не
закрытого scope: это был бы route undershoot. Чтобы до закрытия P4.10/P4.11 устранить единственный
глобальный debt `CFH-55`, порядок close фиксируется `P4.12 → P4.10 → P4.11`; P4.13 затем
интеграционно перепроверяет весь join. `RAG:— (repo-grounded: plan.md §3 P4.10–P4.12;
phases/P4.md items; workflows/wf-agentools-p4-remediation.js до D154; D149)`.

## 4. Pre-mortem и mitigations

| Причина провала | Ранний сигнал | Mitigation/owner |
|:--|:--|:--|
| Author P4.12 не может создать CFH-55 pair | write outside declared workflow files | exact P4.12 write-set включает schema+harness; owner W2 |
| Валидация одного автора читает наполовину изменённый checker | параллельный `spec_lint` даёт плавающий вывод | общие gates только после join; sequential closer |
| Low closer фактически исполняет high item | Task Contract route mismatch или нет доказуемого executor route | closer = implementation/high; фактический route в Completion Notes/journal |
| Evidence-map «достигает 32» фиктивными строками | отсутствует source row для carrier | exact 27 fixture ids + 116 rule ids; headline хранится отдельно |
| P4.13 объявляет Gate B GREEN | A3 artifact содержит содержательный verdict | P4.13 только evidence/A3; единственный semantic verdict — cold-start P5.1 round 2 |
