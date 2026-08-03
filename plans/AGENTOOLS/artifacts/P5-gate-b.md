---
type: gate-artifact
plan: AGENTOOLS
item: P5.1
phase: P5
date: 2026-08-03
round: 1
---

# ГЕЙТ B — адверсариальный аудит волны A

**Вердикт: RED**

Якорь предметного дерева: c761a8e90f7c4ce1012234c69e108ef0e1b18f54. Живые каноны
контуров прочитаны на якоре vault b0cdf6a1e599e4e90260f56078f56561375b3376; выбранные файлы
на этом якоре не имели working-tree diff. Аудит read-only по spec/revisions/2026-08,
fixtures/2026-08, tools и решениям.

## Почему RED

Машинная трассировка действительно даёт 382/382 тестируемых MUST/MUST NOT, но содержательная
проверка опровергает этот сигнал. Тридцать два поимённо разобранных носителя не могут быть
валидными тестами заявленных правил; для 116 обязательных правил все зарегистрированные
носители входят в это множество. Особенно показателен remediation-набор P4.5: все 14 новых
кейсов содержат противоречие норме, невыраженный stimulus либо проверку декларации вместо
субъекта нормы; эти 14 кейсов являются единственными носителями 78 правил. Заморозка такого
корпуса закрепила бы неверный контракт, что по таксономии Gate B является blocker.

### Линза 1 — спека ↔ решения

Матрица DR-01 §A–§J и DR-02 перечитана против таблиц покрытия всех 12 документов.

| Решение | Нормативный адрес | Вердикт |
|:--|:--|:--|
| DR-01 A: unofficial extension, identity, final MCP pin | OVR-15…OVR-17, EXT-1…EXT-17 | адрес есть; EXT-14 сформулирован как specRevision |
| DR-01 B: two-phase, token, policy/protocol, digest, re-auth | TLP-17…TLP-25, CNF-1…CNF-41 | адрес есть; пересмотры не оставили старого edit-in-place |
| DR-01 C: journal truth, concurrency, effects, versions, snapshot, hash chain | ALC-1…ALC-37 | адрес есть |
| DR-01 D: key/fingerprint, three dedupe states, two timers, JCS | IDE-1…IDE-29, AOB-32…AOB-44 | адрес есть |
| DR-01 E: errors, open enums, must-ignore, GREASE, schema hygiene | OVR-7…OVR-11, IDE-30…IDE-48, CFH-50…CFH-51 | адрес есть |
| DR-01 F: closed AST, interpreter only, limits, saga | SCN-1…SCN-30 | адрес есть |
| DR-01 G: synchronous and Task paths, no percentages, reconciliation, cooperative cancel | LOP-1…LOP-27 | адрес есть |
| DR-01 H: audit, links, cost, baggage | AOB-1…AOB-31 | адрес есть |
| DR-01 I: token boundary, provenance, principals, decision audit | TLP-24…TLP-25, SEC-1…SEC-19, AOB-14…AOB-24 | адрес есть |
| DR-01 J: harness, traceability, severity, baseline, CI, dual schemas, golden, lockstep | CFH-1…CFH-54, TLP-26…TLP-34 | адрес есть |
| DR-02: Lists A/B, manual DTO, freshness and thin Dart transport | OVR-10, TLP-26…TLP-34, CFH-27…CFH-35 | DTO вручную согласовано; владелец-гейт §6 не подменён |

Противоречия текста решений нормативному тексту не найдено. Найденные ниже противоречия лежат
между правильным нормативным текстом и корпусом/линтером. Owner-gate DR-02 §6 остаётся
владельцу; аудит его не решал.

### Линза 2 — спека ↔ ADR контуров

Живые ADR и модули Axioma, ADR-020 CoreX и B-13/B-16 Flex прочитаны заново. Контрактных
конфликтов нет: MCP-only, запрет прямого доступа к БД, единые AiGateway/ToolRegistry,
пересечение прав, повторная авторизация, tier/stake и audit совместимы с ревизией. Для
tier/stake, provider gateway, principal/provenance и существующего HITL нужен адаптер, а не
новое поле провода. Полная датированная таблица: findings/P5-adr-contours.md.

### Линза 3 — правила ↔ векторы содержательно

Полностью разобраны 382 тестируемых MUST/MUST NOT. Приложение ниже содержит ровно одну строку
на каждый такой маркер: carrier, наблюдаемый предикат и результат суждения. 116 строк имеют
только негодные носители; это не утверждение на основании зелёного coverage.md, а результат
сверки given/script/subject с нормативным условием.

Отдельные классы вырождения:

- негативные behavior без expect.error: scenario-002 и scncomp-010/011 различимы адресными
  событиями; scnnocode-003, scnorder-002 и profileuntrusted-001 вырождены — generic failure
  не доказывает parse-time/order/provenance причину;
- GREASE behavior-кейсы несут наблюдаемые unknown values, но cfhgrease-001 проверяет лишь
  существование файла и не может доказать CFH-51 «на каждом прогоне обеими реализациями»;
- reject artifact-кейсов без diagnostic_substring нет; хуже другое: accept-кейсы
  ovrdev-001, extidentity-001, extnegotiation-001, toolauthoring-001 и tooldigest-001
  принимают субъект, прямо противоречащий норме;
- запасные spec_rule_ids подтверждены, в частности toolcontract-001 приписывает себе TLP-5
  (runtime structured result), хотя subject содержит только дескриптор; security-001
  приписывает SEC-3, но ожидает код производителя CNF-21.

#### Полная матрица 382 MUST/MUST NOT

| Маркер | Word/L | Carrier | Различающий признак | Аудит |
|:--|:--|:--|:--|:--|
| OVR-1 | MUST/L1 | ovrpin-001 | kind=mcp_pin; linter=accept | PASS |
| OVR-2 | MUST NOT/L1 | ovrlegacy-002 | kind=doc_text; linter=accept | PASS |
| OVR-3 | MUST NOT/L1 | ovrboundary-001 | kind=revision_conventions; linter=accept | PASS |
| OVR-4 | MUST NOT/L1 | ovrboundary-001 | kind=revision_conventions; linter=accept | PASS |
| OVR-5 | MUST NOT/L1 | ovrboundary-001 | kind=revision_conventions; linter=accept | PASS |
| OVR-6 | MUST/L1 | ovrword-002 | kind=rule_line; linter=accept | PASS |
| OVR-7 | MUST/L1 | ovrline-002 | kind=rule_line; linter=accept | PASS |
| OVR-8 | MUST/L1 | ovrfield-001 | kind=tool_descriptor; linter=accept | PASS |
| OVR-9 | MUST/L1 | ovrboundary-001 | kind=revision_conventions; linter=accept | PASS |
| OVR-10 | MUST/L1 | ovrdup-001 | kind=rule_line; linter=reject; diag=дублирующийся маркер | PASS |
| OVR-11 | MUST NOT/L1 | ovrdup-001 | kind=rule_line; linter=reject; diag=дублирующийся маркер | PASS |
| OVR-12 | MUST/L1 | ovrline-002 | kind=rule_line; linter=accept | PASS |
| OVR-13 | MUST/L1 | ovrlevel-001 | kind=revision_conventions; linter=accept | PASS |
| OVR-14 | MUST/L1 | ovrdev-001 | kind=rule_deviation_note; linter=accept | FAIL F6 |
| OVR-15 | MUST NOT/L1 | ovrdesc-001 | kind=schema; linter=reject; diag=норма живёт в markdown-правиле (anti-regress | PASS |
| OVR-16 | MUST/L1 | ovrenum-002 | kind=schema; linter=accept | PASS |
| OVR-17 | MUST/L1 | ovrref-002 | kind=schema; linter=accept | PASS |
| OVR-18 | MUST/L1 | ovrexample-001 | kind=example_block; linter=reject; diag=структурная сверка, anti-regression №2 | PASS |
| OVR-19 | MUST/L1 | grease-001 | events=confirmation_requested,confirmation_resolved; state=completed; call=prepare,commit | PASS |
| OVR-20 | MUST/L1 | grease-004 | events=confirmation_requested,confirmation_resolved; state=completed; call=prepare,commit | PASS |
| OVR-21 | MUST NOT/L1 | ovrsfx-002 | kind=tool_descriptor; linter=accept | PASS |
| OVR-22 | MUST NOT/L1 | ovrrev-001 | kind=revision_status; linter=accept | PASS |
| OVR-23 | MUST/L1 | ovrrev-001 | kind=revision_status; linter=accept | PASS |
| OVR-24 | MUST/L1 | ovrlevel-001 | kind=revision_conventions; linter=accept | PASS |
| OVR-25 | MUST/L1 | ovrgloss-001 | kind=coverage_table; linter=accept | PASS |
| OVR-26 | MUST NOT/L1 | ovrlegacy-002 | kind=doc_text; linter=accept | PASS |
| OVR-27 | MUST/L1 | ovrgloss-001 | kind=coverage_table; linter=accept | PASS |
| EXT-1 | MUST/L1 | extidentity-001 | kind=extension_identity; linter=accept | FAIL F3 |
| EXT-2 | MUST NOT/L1 | extidentity-001 | kind=extension_identity; linter=accept | FAIL F3 |
| EXT-3 | MUST/L1 | extidentity-001 | kind=extension_identity; linter=accept | FAIL F3 |
| EXT-4 | MUST/L1 | extidentity-001 | kind=extension_identity; linter=accept | FAIL F3 |
| EXT-5 | MUST/L1 | extidentity-001 | kind=extension_identity; linter=accept | FAIL F3 |
| EXT-6 | MUST NOT/L1 | extidentity-001 | kind=extension_identity; linter=accept | FAIL F3 |
| EXT-7 | MUST/L1 | extension-001 | events=confirmation_requested,confirmation_resolved,effect_requested,effect_confirmed; state=completed; call=prepare,commit | PASS |
| EXT-8 | MUST/L1 | extidentity-001 | kind=extension_identity; linter=accept | FAIL F3 |
| EXT-9 | MUST NOT/L1 | extidentity-001 | kind=extension_identity; linter=accept | FAIL F3 |
| EXT-10 | MUST NOT/L1 | extension-001 | events=confirmation_requested,confirmation_resolved,effect_requested,effect_confirmed; state=completed; call=prepare,commit | PASS |
| EXT-11 | MUST/L1 | extnegotiation-001 | kind=extension_negotiation; linter=accept | FAIL F3 |
| EXT-12 | MUST NOT/L1 | extnegotiation-001 | kind=extension_negotiation; linter=accept | FAIL F3 |
| EXT-13 | MUST/L1 | extnegotiation-001 | kind=extension_negotiation; linter=accept | FAIL F3 |
| EXT-14 | MUST/L1 | extnegotiation-001 | kind=extension_negotiation; linter=accept | FAIL F3 |
| EXT-15 | MUST/L1 | extnegotiation-001 | kind=extension_negotiation; linter=accept | FAIL F3 |
| EXT-16 | MUST NOT/L1 | extnegotiation-001 | kind=extension_negotiation; linter=accept | FAIL F3 |
| EXT-17 | MUST NOT/L1 | extnegotiation-001 | kind=extension_negotiation; linter=accept | FAIL F3 |
| TLP-1 | MUST/L1 | toolcontract-001 | kind=tool_contract_case; linter=accept | FAIL F3 |
| TLP-2 | MUST/L1 | toolcontract-001 | kind=tool_contract_case; linter=accept | FAIL F3 |
| TLP-5 | MUST/L1 | toolcontract-001 | kind=tool_contract_case; linter=accept | FAIL F3 |
| TLP-6 | MUST NOT/L1 | toolcontract-001 | kind=tool_contract_case; linter=accept | FAIL F3 |
| TLP-7 | MUST/L1 | toolcontract-001 | kind=tool_contract_case; linter=accept | FAIL F3 |
| TLP-9 | MUST/L1 | toolcontract-001 | kind=tool_contract_case; linter=accept | FAIL F3 |
| TLP-10 | MUST/L1 | toolcontract-001 | kind=tool_contract_case; linter=accept | FAIL F3 |
| TLP-11 | MUST NOT/L1 | confpolicy-001 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | FAIL F4 |
| TLP-12 | MUST/L1 | toolcontract-001 | kind=tool_contract_case; linter=accept | FAIL F3 |
| TLP-13 | MUST NOT/L1 | grease-001 | events=confirmation_requested,confirmation_resolved; state=completed; call=prepare,commit | PASS |
| TLP-17 | MUST NOT/L1 | tooldigest-001 | kind=descriptor_digest_case; linter=accept | FAIL F3 |
| TLP-18 | MUST/L1 | tooldigest-001 | kind=descriptor_digest_case; linter=accept | FAIL F3 |
| TLP-19 | MUST/L1 | tooldigest-001 | kind=descriptor_digest_case; linter=accept | FAIL F3 |
| TLP-20 | MUST NOT/L1 | tooldigest-001 | kind=descriptor_digest_case; linter=accept | FAIL F3 |
| TLP-21 | MUST/L1 | tooldigest-001 | kind=descriptor_digest_case; linter=accept | FAIL F3 |
| TLP-22 | MUST/L1 | tooldigest-001 | kind=descriptor_digest_case; linter=accept | FAIL F3 |
| TLP-23 | MUST NOT/L1 | tooldigest-001 | kind=descriptor_digest_case; linter=accept | FAIL F3 |
| TLP-25 | MUST/L3 | profileuntrusted-001 | events=action_created; state=failed | FAIL F4 |
| TLP-26 | MUST NOT/L1 | toolauthoring-001 | kind=authoring_schema_profile; linter=accept | FAIL F3 |
| TLP-27 | MUST/L1 | toolauthoring-001 | kind=authoring_schema_profile; linter=accept | FAIL F3 |
| TLP-28 | MUST/L1 | toolauthoring-001 | kind=authoring_schema_profile; linter=accept | FAIL F3 |
| TLP-29 | MUST NOT/L1 | dosnetwork-001 | kind=schema; linter=reject; diag=сетевой $ref | PASS |
| TLP-30 | MUST/L1 | toolauthoring-001 | kind=authoring_schema_profile; linter=accept | FAIL F3 |
| TLP-31 | MUST/L1 | toolauthoring-001 | kind=authoring_schema_profile; linter=accept | FAIL F3 |
| TLP-32 | MUST/L1 | toolauthoring-001 | kind=authoring_schema_profile; linter=accept | FAIL F3 |
| TLP-33 | MUST/L1 | dosdepth-001 | kind=schema; linter=reject; diag=глубина схемы 17 превышает максимум 16 | PASS |
| TLP-34 | MUST/L1 | dossubschemas-001 | kind=schema; linter=reject; diag=число подсхем 257 превышает максимум 256 | PASS |
| ALC-1 | MUST/L1 | lifecycle-001 | events=action_created,action_started,action_completed; state=completed; call=prepare | PASS |
| ALC-2 | MUST NOT/L1 | lifecyclefold-001 | events=action_created,action_started,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-3 | MUST/L1 | lifecyclefold-001 | events=action_created,action_started,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-4 | MUST NOT/L1 | lifecyclefold-001 | events=action_created,action_started,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-5 | MUST/L1 | lifecycle-001 | events=action_created,action_started,action_completed; state=completed; call=prepare | PASS |
| ALC-6 | MUST/L1 | lifecyclefold-001 | events=action_created,action_started,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-7 | MUST NOT/L1 | lifecyclefold-001 | events=action_created,action_started,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-8 | MUST/L1 | confirmation-001 | events=action_created,action_started,input_recorded,confirmation_requested; state=completed; call=prepare,commit | PASS |
| ALC-9 | MUST/L1 | lifecycle-001 | events=action_created,action_started,action_completed; state=completed; call=prepare | PASS |
| ALC-10 | MUST/L1 | lifecycle-001 | events=action_created,action_started,action_completed; state=completed; call=prepare | PASS |
| ALC-11 | MUST/L1 | lifecycle-003 | events=confirmation_requested,confirmation_resolved; state=rejected | PASS |
| ALC-12 | MUST/L1 | lifecycle-003 | events=confirmation_requested,confirmation_resolved; state=rejected | PASS |
| ALC-13 | MUST NOT/L1 | lifecycle-003 | events=confirmation_requested,confirmation_resolved; state=rejected | PASS |
| ALC-14 | MUST/L1 | lifecycleindex-001 | kind=lifecycle_index; linter=accept | FAIL F3 |
| ALC-15 | MUST/L1 | lifecyclefold-001 | events=action_created,action_started,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-16 | MUST/L1 | lifecyclefold-001 | events=action_created,action_started,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-17 | MUST/L1 | confirmation-001 | events=action_created,action_started,input_recorded,confirmation_requested; state=completed; call=prepare,commit | PASS |
| ALC-18 | MUST NOT/L1 | lifecyclefold-001 | events=action_created,action_started,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-19 | MUST/L1 | lifecycleconcurrency-001 | events=effect_requested,effect_confirmed; state=completed; call=commit | FAIL F4 |
| ALC-20 | MUST/L1 | lifecycleconcurrency-001 | events=effect_requested,effect_confirmed; state=completed; call=commit | FAIL F4 |
| ALC-21 | MUST/L1 | lifecycleconcurrency-001 | events=effect_requested,effect_confirmed; state=completed; call=commit | FAIL F4 |
| ALC-22 | MUST/L1 | lifecycleconcurrency-001 | events=effect_requested,effect_confirmed; state=completed; call=commit | FAIL F4 |
| ALC-23 | MUST NOT/L1 | lifecycleconcurrency-001 | events=effect_requested,effect_confirmed; state=completed; call=commit | FAIL F4 |
| ALC-24 | MUST/L1 | confirmation-001 | events=action_created,action_started,input_recorded,confirmation_requested; state=completed; call=prepare,commit | PASS |
| ALC-25 | MUST/L1 | lifecycleconcurrency-001 | events=effect_requested,effect_confirmed; state=completed; call=commit | FAIL F4 |
| ALC-26 | MUST/L1 | lifecycleconcurrency-001 | events=effect_requested,effect_confirmed; state=completed; call=commit | FAIL F4 |
| ALC-27 | MUST/L1 | lifecycleconcurrency-001 | events=effect_requested,effect_confirmed; state=completed; call=commit | FAIL F4 |
| ALC-28 | MUST NOT/L1 | lifecycleintegrity-001 | events=action_created,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-29 | MUST/L1 | lifecycle-001 | events=action_created,action_started,action_completed; state=completed; call=prepare | PASS |
| ALC-30 | MUST/L1 | lifecycleintegrity-001 | events=action_created,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-31 | MUST NOT/L1 | lifecycleintegrity-001 | events=action_created,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-33 | MUST/L3 | lifecycleintegrity-001 | events=action_created,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-34 | MUST NOT/L3 | lifecycleintegrity-001 | events=action_created,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-35 | MUST/L3 | lifecycleintegrity-001 | events=action_created,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-36 | MUST/L3 | lifecycleintegrity-001 | events=action_created,action_completed; state=completed; call=prepare | FAIL F4 |
| ALC-37 | MUST/L3 | lifecycleintegrity-001 | events=action_created,action_completed; state=completed; call=prepare | FAIL F4 |
| CNF-1 | MUST NOT/L1 | clarification-010 | events=input_recorded,confirmation_requested,confirmation_resolved; state=completed | PASS |
| CNF-2 | MUST/L1 | confirmation-001 | events=action_created,action_started,input_recorded,confirmation_requested; state=completed; call=prepare,commit | PASS |
| CNF-3 | MUST/L1 | confpolicy-001 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | FAIL F4 |
| CNF-4 | MUST/L1 | confpolicy-001 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | FAIL F4 |
| CNF-5 | MUST/L1 | confpolicy-001 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | FAIL F4 |
| CNF-6 | MUST NOT/L1 | confpolicy-001 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | FAIL F4 |
| CNF-7 | MUST/L1 | profile-001 | events=confirmation_requested; state=awaiting_confirmation | PASS |
| CNF-8 | MUST NOT/L1 | confpolicy-001 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | FAIL F4 |
| CNF-9 | MUST/L1 | confirmation-001 | events=action_created,action_started,input_recorded,confirmation_requested; state=completed; call=prepare,commit | PASS |
| CNF-10 | MUST/L1 | confirmation-001 | events=action_created,action_started,input_recorded,confirmation_requested; state=completed; call=prepare,commit | PASS |
| CNF-11 | MUST/L1 | confirmation-001 | events=action_created,action_started,input_recorded,confirmation_requested; state=completed; call=prepare,commit | PASS |
| CNF-12 | MUST/L1 | confirmation-001 | events=action_created,action_started,input_recorded,confirmation_requested; state=completed; call=prepare,commit | PASS |
| CNF-13 | MUST NOT/L1 | conftoken-001 | events=confirmation_resolved; state=completed; call=commit | FAIL F4 |
| CNF-14 | MUST/L1 | confirmation-002 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | PASS |
| CNF-15 | MUST/L1 | confirmation-002 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | PASS |
| CNF-16 | MUST/L1 | confirmation-003 | error=agentools.precondition.confirmation_token_spent; events=confirmation_requested,confirmation_resolved,effect_requested,effect_confirmed; state=completed | PASS |
| CNF-17 | MUST NOT/L1 | confirmation-002 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | PASS |
| CNF-18 | MUST/L1 | confirmation-004 | error=agentools.precondition.confirmation_args_mismatch; events=confirmation_requested,confirmation_resolved; state=failed | PASS |
| CNF-19 | MUST/L1 | confirmation-005 | error=agentools.precondition.confirmation_expired; events=confirmation_requested,confirmation_resolved; state=expired | PASS |
| CNF-20 | MUST/L1 | confirmation-003 | error=agentools.precondition.confirmation_token_spent; events=confirmation_requested,confirmation_resolved,effect_requested,effect_confirmed; state=completed | PASS |
| CNF-21 | MUST/L1 | conftoken-001 | events=confirmation_resolved; state=completed; call=commit | FAIL F4 |
| CNF-22 | MUST/L1 | confirmation-003 | error=agentools.precondition.confirmation_token_spent; events=confirmation_requested,confirmation_resolved,effect_requested,effect_confirmed; state=completed | PASS |
| CNF-23 | MUST/L1 | conftoken-001 | events=confirmation_resolved; state=completed; call=commit | FAIL F4 |
| CNF-24 | MUST/L1 | confirmation-008 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | PASS |
| CNF-25 | MUST/L1 | confirmation-006 | events=confirmation_requested,confirmation_resolved; state=rejected | PASS |
| CNF-26 | MUST NOT/L1 | confirmation-008 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | PASS |
| CNF-27 | MUST/L1 | confirmation-008 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | PASS |
| CNF-28 | MUST NOT/L1 | confirmation-008 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | PASS |
| CNF-29 | MUST/L1 | confirmation-005 | error=agentools.precondition.confirmation_expired; events=confirmation_requested,confirmation_resolved; state=expired | PASS |
| CNF-30 | MUST/L1 | confirmation-005 | error=agentools.precondition.confirmation_expired; events=confirmation_requested,confirmation_resolved; state=expired | PASS |
| CNF-31 | MUST/L1 | confirmation-005 | error=agentools.precondition.confirmation_expired; events=confirmation_requested,confirmation_resolved; state=expired | PASS |
| CNF-33 | MUST/L3 | confdual-001 | events=confirmation_resolved; state=completed; call=commit | FAIL F4 |
| CNF-34 | MUST NOT/L3 | confdual-001 | events=confirmation_resolved; state=completed; call=commit | FAIL F4 |
| CNF-35 | MUST/L1 | confpolicy-001 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | FAIL F4 |
| CNF-36 | MUST NOT/L1 | confpolicy-001 | events=confirmation_requested,confirmation_resolved; state=completed; call=commit | FAIL F4 |
| CNF-37 | MUST/L1 | confirmation-009 | events=confirmation_requested,confirmation_resolved; state=completed | PASS |
| CNF-38 | MUST/L1 | conftoken-001 | events=confirmation_resolved; state=completed; call=commit | FAIL F4 |
| CNF-39 | MUST/L1 | confirmation-006 | events=confirmation_requested,confirmation_resolved; state=rejected | PASS |
| CNF-40 | MUST/L1 | confirmation-009 | events=confirmation_requested,confirmation_resolved; state=completed | PASS |
| CNF-41 | MUST NOT/L1 | confirmation-009 | events=confirmation_requested,confirmation_resolved; state=completed | PASS |
| CLR-1 | MUST/L2 | clarification-010 | events=input_recorded,confirmation_requested,confirmation_resolved; state=completed | PASS |
| CLR-2 | MUST/L2 | clarificationstate-001 | events=input_recorded,action_completed; state=completed | FAIL F4 |
| CLR-3 | MUST NOT/L2 | clarification-011 | events=input_recorded,confirmation_requested,confirmation_resolved; state=completed; call=prepare,commit | PASS |
| CLR-4 | MUST/L2 | clarification-010 | events=input_recorded,confirmation_requested,confirmation_resolved; state=completed | PASS |
| CLR-5 | MUST/L2 | clarification-007 | events=input_recorded; state=running | PASS |
| CLR-6 | MUST/L2 | clarification-001 | events=input_recorded,confirmation_requested,confirmation_resolved; state=completed | PASS |
| CLR-7 | MUST/L2 | clarification-001 | events=input_recorded,confirmation_requested,confirmation_resolved; state=completed | PASS |
| CLR-8 | MUST NOT/L2 | clarificationstate-001 | events=input_recorded,action_completed; state=completed | FAIL F4 |
| CLR-9 | MUST NOT/L2 | clarification-006 | events=input_recorded,confirmation_requested; state=completed | PASS |
| CLR-10 | MUST/L2 | clarification-002 | events=input_recorded,confirmation_requested; state=completed | PASS |
| CLR-11 | MUST/L2 | clarification-002 | events=input_recorded,confirmation_requested; state=completed | PASS |
| CLR-12 | MUST/L2 | clarification-009 | error=agentools.budget.clarification_rounds_exhausted; events=input_recorded; state=failed | PASS |
| CLR-14 | MUST/L2 | clarification-001 | events=input_recorded,confirmation_requested,confirmation_resolved; state=completed | PASS |
| CLR-15 | MUST/L2 | clarification-001 | events=input_recorded,confirmation_requested,confirmation_resolved; state=completed | PASS |
| CLR-16 | MUST/L2 | clarification-008 | events=input_recorded; state=running | PASS |
| CLR-17 | MUST NOT/L2 | clarification-008 | events=input_recorded; state=running | PASS |
| CLR-18 | MUST/L2 | clarification-004 | error=agentools.validation.unknown_result_type; events=action_created,action_started; state=failed | PASS |
| CLR-19 | MUST/L2 | clarification-005 | events=action_completed; state=completed | PASS |
| CLR-20 | MUST NOT/L2 | clarification-010 | events=input_recorded,confirmation_requested,confirmation_resolved; state=completed | PASS |
| CLR-21 | MUST NOT/L2 | clarification-010 | events=input_recorded,confirmation_requested,confirmation_resolved; state=completed | PASS |
| SCN-1 | MUST/L2 | scenario-001 | events=scenario_started,step_started,scenario_completed; state=completed | PASS |
| SCN-2 | MUST/L2 | scenario-001 | events=scenario_started,step_started,scenario_completed; state=completed | PASS |
| SCN-3 | MUST/L2 | scenario-001 | events=scenario_started,step_started,scenario_completed; state=completed | PASS |
| SCN-4 | MUST NOT/L2 | scenario-001 | events=scenario_started,step_started,scenario_completed; state=completed | PASS |
| SCN-5 | MUST/L2 | scenario-001 | events=scenario_started,step_started,scenario_completed; state=completed | PASS |
| SCN-6 | MUST/L2 | scnfailure-004 | events=action_failed,action_created,action_completed; state=completed; call=commit | PASS |
| SCN-7 | MUST/L2 | scnfailure-004 | events=action_failed,action_created,action_completed; state=completed; call=commit | PASS |
| SCN-8 | MUST/L2 | scnfailure-004 | events=action_failed,action_created,action_completed; state=completed; call=commit | PASS |
| SCN-9 | MUST NOT/L2 | scnorder-002 | events=action_failed; state=failed | FAIL F5 |
| SCN-10 | MUST/L2 | scnfailure-004 | events=action_failed,action_created,action_completed; state=completed; call=commit | PASS |
| SCN-11 | MUST/L2 | scnfailure-004 | events=action_failed,action_created,action_completed; state=completed; call=commit | PASS |
| SCN-12 | MUST/L2 | scnfailure-004 | events=action_failed,action_created,action_completed; state=completed; call=commit | PASS |
| SCN-13 | MUST/L2 | scenario-003 | error=demo.validation.expression_syntax; events=step_failed; state=failed | PASS |
| SCN-14 | MUST/L2 | scenario-003 | error=demo.validation.expression_syntax; events=step_failed; state=failed | PASS |
| SCN-15 | MUST/L2 | scenario-003 | error=demo.validation.expression_syntax; events=step_failed; state=failed | PASS |
| SCN-16 | MUST/L2 | scnlimit-005 | error=agentools.budget.limit_exceeded; events=audit.limit_exceeded,action_failed; state=failed | PASS |
| SCN-17 | MUST/L2 | scnlimit-006 | error=agentools.budget.limit_exceeded; events=audit.limit_exceeded,action_failed; state=failed | PASS |
| SCN-18 | MUST/L2 | scnlimit-007 | error=agentools.budget.limit_exceeded; events=audit.limit_exceeded,action_failed; state=failed | PASS |
| SCN-19 | MUST/L2 | scnlimit-008 | error=agentools.budget.limit_exceeded; events=audit.limit_exceeded,action_failed; state=failed | PASS |
| SCN-20 | MUST/L2 | scnlimit-009 | error=agentools.budget.limit_exceeded; events=audit.limit_exceeded,action_failed; state=failed | PASS |
| SCN-21 | MUST/L2 | scnlimit-009 | error=agentools.budget.limit_exceeded; events=audit.limit_exceeded,action_failed; state=failed | PASS |
| SCN-22 | MUST/L2 | scnlimit-009 | error=agentools.budget.limit_exceeded; events=audit.limit_exceeded,action_failed; state=failed | PASS |
| SCN-23 | MUST/L2 | scncomp-010 | events=action_completed,action_failed,audit.compensation_executed,audit.scenario_run_finished; state=failed | PASS |
| SCN-24 | MUST/L2 | scncomp-010 | events=action_completed,action_failed,audit.compensation_executed,audit.scenario_run_finished; state=failed | PASS |
| SCN-25 | MUST/L2 | scncomp-010 | events=action_completed,action_failed,audit.compensation_executed,audit.scenario_run_finished; state=failed | PASS |
| SCN-26 | MUST/L2 | scncomp-011 | events=action_failed,audit.scenario_run_finished; state=failed | PASS |
| SCN-27 | MUST/L2 | scncomp-011 | events=action_failed,audit.scenario_run_finished; state=failed | PASS |
| SCN-28 | MUST/L2 | scenario-010 | error=demo.conflict.compensation_failed; events=compensation_failed,scenario_completed; state=failed | PASS |
| SCN-29 | MUST NOT/L2 | scnoutcome-012 | events=input_recorded,action_completed,audit.scenario_run_finished; state=completed | PASS |
| SCN-30 | MUST/L2 | scnoutcome-012 | events=input_recorded,action_completed,audit.scenario_run_finished; state=completed | PASS |
| LOP-1 | MUST NOT/L2 | loptask-001 | events=confirmation_requested,confirmation_resolved,effect_requested,task_bound; state=completed; call=commit | PASS |
| LOP-2 | MUST/L2 | loptask-001 | events=confirmation_requested,confirmation_resolved,effect_requested,task_bound; state=completed; call=commit | PASS |
| LOP-3 | MUST/L2 | loptask-001 | events=confirmation_requested,confirmation_resolved,effect_requested,task_bound; state=completed; call=commit | PASS |
| LOP-4 | MUST NOT/L2 | loptask-001 | events=confirmation_requested,confirmation_resolved,effect_requested,task_bound; state=completed; call=commit | PASS |
| LOP-5 | MUST/L2 | lopsync-002 | events=confirmation_requested,confirmation_resolved,effect_requested; state=applying; call=commit | PASS |
| LOP-6 | MUST/L2 | lopsignal-003 | events=task_bound,action_completed; state=completed | PASS |
| LOP-7 | MUST NOT/L2 | lopsignal-003 | events=task_bound,action_completed; state=completed | PASS |
| LOP-8 | MUST/L2 | lopsignal-003 | events=task_bound,action_completed; state=completed | PASS |
| LOP-10 | MUST/L2 | longop-003 | events=task_bound,task_status_observed; state=completed | PASS |
| LOP-11 | MUST NOT/L2 | longop-003 | events=task_bound,task_status_observed; state=completed | PASS |
| LOP-12 | MUST/L2 | longop-004 | error=agentools.precondition.task_input_required_while_applying; events=task_status_observed,action_failed; state=failed | PASS |
| LOP-13 | MUST/L2 | longop-004 | error=agentools.precondition.task_input_required_while_applying; events=task_status_observed,action_failed; state=failed | PASS |
| LOP-14 | MUST/L2 | longop-004 | error=agentools.precondition.task_input_required_while_applying; events=task_status_observed,action_failed; state=failed | PASS |
| LOP-15 | MUST/L2 | loprecon-005 | events=effect_requested,task_binding_lost,effect_confirmed; state=running | PASS |
| LOP-16 | MUST/L2 | loprecon-005 | events=effect_requested,task_binding_lost,effect_confirmed; state=running | PASS |
| LOP-17 | MUST NOT/L2 | loprecon-005 | events=effect_requested,task_binding_lost,effect_confirmed; state=running | PASS |
| LOP-18 | MUST/L2 | loprecon-005 | events=effect_requested,task_binding_lost,effect_confirmed; state=running | PASS |
| LOP-19 | MUST NOT/L2 | loprecon-005 | events=effect_requested,task_binding_lost,effect_confirmed; state=running | PASS |
| LOP-20 | MUST/L2 | lopcancel-006 | events=effect_requested,cancellation_requested; state=running | PASS |
| LOP-21 | MUST NOT/L2 | lopcancel-006 | events=effect_requested,cancellation_requested; state=running | PASS |
| LOP-22 | MUST NOT/L2 | lopcancel-006 | events=effect_requested,cancellation_requested; state=running | PASS |
| LOP-23 | MUST NOT/L2 | lopcancel-006 | events=effect_requested,cancellation_requested; state=running | PASS |
| LOP-24 | MUST NOT/L2 | lopguard-007 | events=cancellation_requested,action_cancelled; state=cancelled | PASS |
| LOP-25 | MUST NOT/L2 | lopguard-007 | events=cancellation_requested,action_cancelled; state=cancelled | PASS |
| LOP-26 | MUST/L2 | lopbound-008 | error=agentools.precondition.task_input_required_while_applying; events=effect_requested; state=applying | PASS |
| LOP-27 | MUST/L2 | longop-003 | events=task_bound,task_status_observed; state=completed | PASS |
| AOB-1 | MUST/L3 | audit-001 | events=audit.policy_evaluated; state=completed; call=prepare | PASS |
| AOB-2 | MUST/L3 | audit-001 | events=audit.policy_evaluated; state=completed; call=prepare | PASS |
| AOB-3 | MUST NOT/L3 | audit-001 | events=audit.policy_evaluated; state=completed; call=prepare | PASS |
| AOB-4 | MUST NOT/L3 | audit-001 | events=audit.policy_evaluated; state=completed; call=prepare | PASS |
| AOB-5 | MUST NOT/L3 | audit-001 | events=audit.policy_evaluated; state=completed; call=prepare | PASS |
| AOB-6 | MUST/L3 | audit-001 | events=audit.policy_evaluated; state=completed; call=prepare | PASS |
| AOB-7 | MUST/L3 | audit-001 | events=audit.policy_evaluated; state=completed; call=prepare | PASS |
| AOB-8 | MUST/L3 | audit-001 | events=audit.policy_evaluated; state=completed; call=prepare | PASS |
| AOB-9 | MUST/L3 | audit-002 | events=audit.policy_evaluated,audit.cost_recorded; state=completed | PASS |
| AOB-10 | MUST/L3 | audit-002 | events=audit.policy_evaluated,audit.cost_recorded; state=completed | PASS |
| AOB-11 | MUST/L3 | audit-002 | events=audit.policy_evaluated,audit.cost_recorded; state=completed | PASS |
| AOB-12 | MUST/L3 | audit-002 | events=audit.policy_evaluated,audit.cost_recorded; state=completed | PASS |
| AOB-13 | MUST/L3 | audit-002 | events=audit.policy_evaluated,audit.cost_recorded; state=completed | PASS |
| AOB-14 | MUST/L3 | audit-002 | events=audit.policy_evaluated,audit.cost_recorded; state=completed | PASS |
| AOB-15 | MUST/L3 | audit-002 | events=audit.policy_evaluated,audit.cost_recorded; state=completed | PASS |
| AOB-16 | MUST NOT/L3 | audit-002 | events=audit.policy_evaluated,audit.cost_recorded; state=completed | PASS |
| AOB-18 | MUST NOT/L3 | audit-003 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed | PASS |
| AOB-19 | MUST NOT/L3 | audit-003 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed | PASS |
| AOB-20 | MUST/L3 | audit-003 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed | PASS |
| AOB-21 | MUST/L3 | audit-003 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed | PASS |
| AOB-22 | MUST NOT/L3 | audit-003 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed | PASS |
| AOB-23 | MUST/L3 | audit-003 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed | PASS |
| AOB-24 | MUST NOT/L3 | audit-003 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed | PASS |
| AOB-25 | MUST/L3 | audit-004 | error=agentools.budget.baggage_limit_exceeded; events=audit.limit_exceeded; state=failed | FAIL F2 |
| AOB-26 | MUST NOT/L3 | audit-004 | error=agentools.budget.baggage_limit_exceeded; events=audit.limit_exceeded; state=failed | FAIL F2 |
| AOB-27 | MUST/L3 | audit-004 | error=agentools.budget.baggage_limit_exceeded; events=audit.limit_exceeded; state=failed | FAIL F2 |
| AOB-28 | MUST NOT/L3 | audit-004 | error=agentools.budget.baggage_limit_exceeded; events=audit.limit_exceeded; state=failed | FAIL F2 |
| AOB-29 | MUST/L3 | audit-004 | error=agentools.budget.baggage_limit_exceeded; events=audit.limit_exceeded; state=failed | FAIL F2 |
| AOB-30 | MUST NOT/L3 | audit-004 | error=agentools.budget.baggage_limit_exceeded; events=audit.limit_exceeded; state=failed | FAIL F2 |
| AOB-31 | MUST/L3 | audit-004 | error=agentools.budget.baggage_limit_exceeded; events=audit.limit_exceeded; state=failed | FAIL F2 |
| AOB-32 | MUST/L1 | golden/jcs/bmp-weird | golden-bytes pair golden/jcs/bmp-weird | PASS |
| AOB-33 | MUST/L1 | golden/jcs/bmp-weird | golden-bytes pair golden/jcs/bmp-weird | PASS |
| AOB-34 | MUST/L1 | golden/jcs/bmp-weird | golden-bytes pair golden/jcs/bmp-weird | PASS |
| AOB-35 | MUST/L1 | golden/jcs/rfc8785-values | golden-bytes pair golden/jcs/rfc8785-values | PASS |
| AOB-36 | MUST/L1 | golden/jcs/rfc8785-values | golden-bytes pair golden/jcs/rfc8785-values | PASS |
| AOB-37 | MUST/L1 | golden/jcs/duplicate-keys | error pair golden/jcs/duplicate-keys | PASS |
| AOB-38 | MUST NOT/L1 | golden/jcs/bmp-weird | golden-bytes pair golden/jcs/bmp-weird | PASS |
| AOB-39 | MUST/L1 | golden/jcs/rfc8785-values | golden-bytes pair golden/jcs/rfc8785-values | PASS |
| AOB-40 | MUST/L1 | golden/jcs/rfc8785-values | golden-bytes pair golden/jcs/rfc8785-values | PASS |
| AOB-41 | MUST/L1 | golden/jcs/bmp-weird | golden-bytes pair golden/jcs/bmp-weird | PASS |
| AOB-42 | MUST/L1 | golden/jcs/bmp-weird | golden-bytes pair golden/jcs/bmp-weird | PASS |
| AOB-43 | MUST/L1 | golden/jcs/bmp-weird | golden-bytes pair golden/jcs/bmp-weird | PASS |
| AOB-44 | MUST/L1 | golden/jcs/bmp-weird | golden-bytes pair golden/jcs/bmp-weird | PASS |
| IDE-1 | MUST/L1 | idempotency-001 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed; call=commit | PASS |
| IDE-2 | MUST NOT/L1 | idempotency-001 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed; call=commit | PASS |
| IDE-3 | MUST/L1 | idempotency-001 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed; call=commit | PASS |
| IDE-4 | MUST/L1 | idempotency-001 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed; call=commit | PASS |
| IDE-5 | MUST/L1 | idempotency-007 | events=confirmation_requested,action_completed; state=completed | PASS |
| IDE-6 | MUST/L1 | golden/idempotency/key-composition-003 | golden-bytes pair golden/idempotency/key-composition-003 | PASS |
| IDE-7 | MUST/L1 | golden/idempotency/key-composition-003 | golden-bytes pair golden/idempotency/key-composition-003 | PASS |
| IDE-8 | MUST NOT/L1 | golden/idempotency/key-composition-003 | golden-bytes pair golden/idempotency/key-composition-003 | PASS |
| IDE-9 | MUST/L1 | idempotency-001 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed; call=commit | PASS |
| IDE-10 | MUST/L1 | idempotency-003 | error=agentools.conflict.idempotency_in_flight; events=confirmation_requested,confirmation_resolved; state=applying | PASS |
| IDE-11 | MUST/L1 | idempotency-001 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed; call=commit | PASS |
| IDE-12 | MUST/L1 | idempotency-002 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed | PASS |
| IDE-13 | MUST NOT/L1 | idempotency-002 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed | PASS |
| IDE-14 | MUST/L1 | idempotency-003 | error=agentools.conflict.idempotency_in_flight; events=confirmation_requested,confirmation_resolved; state=applying | PASS |
| IDE-15 | MUST NOT/L1 | idempotency-003 | error=agentools.conflict.idempotency_in_flight; events=confirmation_requested,confirmation_resolved; state=applying | PASS |
| IDE-16 | MUST/L1 | idempotency-004 | error=agentools.validation.idempotency_key_reuse; events=action_failed; state=failed | PASS |
| IDE-17 | MUST/L1 | idempotency-004 | error=agentools.validation.idempotency_key_reuse; events=action_failed; state=failed | PASS |
| IDE-18 | MUST/L1 | idempotency-004 | error=agentools.validation.idempotency_key_reuse; events=action_failed; state=failed | PASS |
| IDE-19 | MUST/L1 | idempotency-004 | error=agentools.validation.idempotency_key_reuse; events=action_failed; state=failed | PASS |
| IDE-20 | MUST NOT/L1 | idempotency-004 | error=agentools.validation.idempotency_key_reuse; events=action_failed; state=failed | PASS |
| IDE-21 | MUST/L1 | idempotency-004 | error=agentools.validation.idempotency_key_reuse; events=action_failed; state=failed | PASS |
| IDE-22 | MUST/L1 | errors-001 | error=fina.validation.period_closed; events=confirmation_requested,confirmation_resolved; state=failed | PASS |
| IDE-23 | MUST/L1 | errors-001 | error=fina.validation.period_closed; events=confirmation_requested,confirmation_resolved; state=failed | PASS |
| IDE-24 | MUST NOT/L1 | errors-001 | error=fina.validation.period_closed; events=confirmation_requested,confirmation_resolved; state=failed | PASS |
| IDE-25 | MUST/L1 | errors-001 | error=fina.validation.period_closed; events=confirmation_requested,confirmation_resolved; state=failed | PASS |
| IDE-26 | MUST/L1 | errors-001 | error=fina.validation.period_closed; events=confirmation_requested,confirmation_resolved; state=failed | PASS |
| IDE-27 | MUST NOT/L1 | errors-001 | error=fina.validation.period_closed; events=confirmation_requested,confirmation_resolved; state=failed | PASS |
| IDE-28 | MUST/L1 | errors-001 | error=fina.validation.period_closed; events=confirmation_requested,confirmation_resolved; state=failed | PASS |
| IDE-29 | MUST/L1 | errors-001 | error=fina.validation.period_closed; events=confirmation_requested,confirmation_resolved; state=failed | PASS |
| IDE-30 | MUST/L1 | idempotency-005 | error=fina.validation.period_closed; events=confirmation_requested,action_failed; state=failed | PASS |
| IDE-31 | MUST/L1 | idempotency-003 | error=agentools.conflict.idempotency_in_flight; events=confirmation_requested,confirmation_resolved; state=applying | PASS |
| IDE-32 | MUST NOT/L1 | idempotency-005 | error=fina.validation.period_closed; events=confirmation_requested,action_failed; state=failed | PASS |
| IDE-33 | MUST/L1 | idempotency-003 | error=agentools.conflict.idempotency_in_flight; events=confirmation_requested,confirmation_resolved; state=applying | PASS |
| IDE-34 | MUST/L1 | idempotency-005 | error=fina.validation.period_closed; events=confirmation_requested,action_failed; state=failed | PASS |
| IDE-35 | MUST/L1 | idempotency-005 | error=fina.validation.period_closed; events=confirmation_requested,action_failed; state=failed | PASS |
| IDE-36 | MUST NOT/L1 | idempotency-005 | error=fina.validation.period_closed; events=confirmation_requested,action_failed; state=failed | PASS |
| IDE-37 | MUST/L1 | idempotency-005 | error=fina.validation.period_closed; events=confirmation_requested,action_failed; state=failed | PASS |
| IDE-38 | MUST NOT/L1 | grease-001 | events=confirmation_requested,confirmation_resolved; state=completed; call=prepare,commit | PASS |
| IDE-39 | MUST/L1 | grease-001 | events=confirmation_requested,confirmation_resolved; state=completed; call=prepare,commit | PASS |
| IDE-40 | MUST NOT/L1 | idempotency-006 | events=confirmation_requested,action_completed; state=completed; call=commit | PASS |
| IDE-41 | MUST/L1 | idempotency-006 | events=confirmation_requested,action_completed; state=completed; call=commit | PASS |
| IDE-42 | MUST NOT/L1 | idempotency-006 | events=confirmation_requested,action_completed; state=completed; call=commit | PASS |
| IDE-43 | MUST NOT/L1 | mustignore-002 | events=confirmation_requested,confirmation_resolved,action_completed; state=completed | PASS |
| SEC-1 | MUST NOT/L1 | security-001 | error=agentools.permission.confirmation_principal_mismatch; events=action_failed; state=failed | FAIL F1 |
| SEC-2 | MUST/L1 | security-001 | error=agentools.permission.confirmation_principal_mismatch; events=action_failed; state=failed | FAIL F1 |
| SEC-3 | MUST/L1 | security-001 | error=agentools.permission.confirmation_principal_mismatch; events=action_failed; state=failed | FAIL F1 |
| SEC-4 | MUST NOT/L1 | security-001 | error=agentools.permission.confirmation_principal_mismatch; events=action_failed; state=failed | FAIL F1 |
| SEC-5 | MUST NOT/L1 | security-001 | error=agentools.permission.confirmation_principal_mismatch; events=action_failed; state=failed | FAIL F1 |
| SEC-6 | MUST NOT/L1 | security-001 | error=agentools.permission.confirmation_principal_mismatch; events=action_failed; state=failed | FAIL F1 |
| SEC-7 | MUST/L1 | security-002 | error=agentools.permission.confirmation_principal_mismatch; events=input_recorded,action_failed; state=failed | FAIL F2 |
| SEC-8 | MUST NOT/L1 | security-002 | error=agentools.permission.confirmation_principal_mismatch; events=input_recorded,action_failed; state=failed | FAIL F2 |
| SEC-9 | MUST/L1 | security-002 | error=agentools.permission.confirmation_principal_mismatch; events=input_recorded,action_failed; state=failed | FAIL F2 |
| SEC-10 | MUST NOT/L1 | security-002 | error=agentools.permission.confirmation_principal_mismatch; events=input_recorded,action_failed; state=failed | FAIL F2 |
| SEC-11 | MUST NOT/L1 | security-002 | error=agentools.permission.confirmation_principal_mismatch; events=input_recorded,action_failed; state=failed | FAIL F2 |
| SEC-12 | MUST/L1 | security-002 | error=agentools.permission.confirmation_principal_mismatch; events=input_recorded,action_failed; state=failed | FAIL F2 |
| SEC-13 | MUST/L1 | security-003 | error=agentools.permission.confirmation_principal_mismatch; events=action_failed; state=failed | FAIL F2 |
| SEC-14 | MUST/L1 | security-003 | error=agentools.permission.confirmation_principal_mismatch; events=action_failed; state=failed | FAIL F2 |
| SEC-15 | MUST NOT/L1 | security-003 | error=agentools.permission.confirmation_principal_mismatch; events=action_failed; state=failed | FAIL F2 |
| SEC-16 | MUST/L1 | security-003 | error=agentools.permission.confirmation_principal_mismatch; events=action_failed; state=failed | FAIL F2 |
| SEC-17 | MUST NOT/L1 | security-003 | error=agentools.permission.confirmation_principal_mismatch; events=action_failed; state=failed | FAIL F2 |
| SEC-18 | MUST NOT/L1 | security-004 | error=agentools.permission.confirmation_principal_mismatch; events=confirmation_resolved,action_failed; state=failed | FAIL F2 |
| SEC-19 | MUST NOT/L1 | security-004 | error=agentools.permission.confirmation_principal_mismatch; events=confirmation_resolved,action_failed; state=failed | FAIL F2 |
| CFH-1 | MUST/L1 | hseq-001 | outcome=conformant | PASS |
| CFH-2 | MUST NOT/L1 | cfhprotocol-001 | kind=harness_boundary; linter=accept | PASS |
| CFH-3 | MUST/L1 | hnostop-001 | outcome=violation; violated=CFH-3 | PASS |
| CFH-4 | MUST/L1 | hverbad-001 | outcome=violation; violated=CFH-4 | PASS |
| CFH-5 | MUST/L1 | cfhprotocol-001 | kind=harness_boundary; linter=accept | PASS |
| CFH-6 | MUST/L1 | hunknown-001 | outcome=violation; violated=CFH-6 | PASS |
| CFH-7 | MUST/L1 | hseq-001 | outcome=conformant | PASS |
| CFH-8 | MUST NOT/L1 | hseq-001 | outcome=conformant | PASS |
| CFH-9 | MUST/L1 | hseq-001 | outcome=conformant | PASS |
| CFH-10 | MUST NOT/L1 | cfhprotocol-001 | kind=harness_boundary; linter=accept | PASS |
| CFH-11 | MUST/L1 | hnotestbad-001 | outcome=violation; violated=CFH-11 | PASS |
| CFH-12 | MUST NOT/L1 | hseq-001 | outcome=conformant | PASS |
| CFH-13 | MUST/L1 | hseq-001 | outcome=conformant | PASS |
| CFH-14 | MUST/L1 | cfhtrace-001 | kind=traceability_index; linter=accept | PASS |
| CFH-15 | MUST/L1 | cfhindex-001 | kind=derived_index; linter=reject; diag=расходится со сгенерированным (--check) | PASS |
| CFH-16 | MUST NOT/L1 | cfhtrace-001 | kind=traceability_index; linter=accept | PASS |
| CFH-17 | MUST/L1 | cfhseverity-001 | kind=conformance_report; linter=accept | PASS |
| CFH-18 | MUST NOT/L1 | cfhseverity-001 | kind=conformance_report; linter=accept | PASS |
| CFH-19 | MUST/L1 | cfhseverity-001 | kind=conformance_report; linter=accept | PASS |
| CFH-20 | MUST/L1 | ovrnotest-001 | kind=rule_line; linter=reject; diag=тихого SKIP не существует (DR-01 §J3) | PASS |
| CFH-21 | MUST NOT/L1 | cfhseverity-001 | kind=conformance_report; linter=accept | PASS |
| CFH-22 | MUST/L1 | cfhbaseline-001 | kind=known_failures; linter=accept | FAIL F6 |
| CFH-23 | MUST/L1 | cfhbaseline-001 | kind=known_failures; linter=accept | FAIL F6 |
| CFH-24 | MUST/L1 | cfhbaseline-001 | kind=known_failures; linter=accept | FAIL F6 |
| CFH-25 | MUST/L1 | cfhbaseline-001 | kind=known_failures; linter=accept | FAIL F6 |
| CFH-26 | MUST NOT/L1 | cfhbaseline-001 | kind=known_failures; linter=accept | FAIL F6 |
| CFH-27 | MUST/L1 | cfhci-001 | kind=ci_pipeline; linter=accept | PASS |
| CFH-28 | MUST/L1 | cfhauthor-002 | kind=derived_schema; linter=accept | PASS |
| CFH-29 | MUST/L1 | cfhci-001 | kind=ci_pipeline; linter=accept | PASS |
| CFH-30 | MUST/L1 | cfhci-001 | kind=ci_pipeline; linter=accept | PASS |
| CFH-31 | MUST/L1 | cfhci-001 | kind=ci_pipeline; linter=accept | PASS |
| CFH-32 | MUST NOT/L1 | cfhci-001 | kind=ci_pipeline; linter=accept | PASS |
| CFH-33 | MUST NOT/L1 | cfhcodegen-001 | kind=codegen_boundary; linter=accept | PASS |
| CFH-34 | MUST NOT/L1 | cfhcodegen-001 | kind=codegen_boundary; linter=accept | PASS |
| CFH-35 | MUST/L1 | hgolden-001 | outcome=conformant | PASS |
| CFH-36 | MUST/L1 | hgolden-001 | outcome=conformant | PASS |
| CFH-37 | MUST NOT/L1 | hgolden-001 | outcome=conformant | PASS |
| CFH-38 | MUST/L1 | hgolden-001 | outcome=conformant | PASS |
| CFH-39 | MUST/L1 | hgolden-001 | outcome=conformant | PASS |
| CFH-40 | MUST NOT/L1 | hgoldenctrl-001 | outcome=conformant | PASS |
| CFH-41 | MUST/L1 | hgoldenctrl-001 | outcome=conformant | PASS |
| CFH-42 | MUST/L1 | hgolden-001 | outcome=conformant | PASS |
| CFH-43 | MUST/L1 | hgolden-001 | outcome=conformant | PASS |
| CFH-44 | MUST/L1 | hgolden-001 | outcome=conformant | PASS |
| CFH-45 | MUST/L1 | hrevmis-001 | outcome=violation; violated=CFH-47 | PASS |
| CFH-46 | MUST/L1 | hrevmis-001 | outcome=violation; violated=CFH-47 | PASS |
| CFH-47 | MUST/L1 | hrevmis-001 | outcome=violation; violated=CFH-47 | PASS |
| CFH-48 | MUST/L1 | grease-003 | events=confirmation_requested,confirmation_resolved; state=completed; call=prepare,commit | PASS |
| CFH-49 | MUST/L1 | grease-003 | events=confirmation_requested,confirmation_resolved; state=completed; call=prepare,commit | PASS |
| CFH-50 | MUST/L1 | cfhgrease-001 | kind=fixture_vector; linter=accept | FAIL F6 |
| CFH-51 | MUST/L1 | cfhgrease-001 | kind=fixture_vector; linter=accept | FAIL F6 |
| CFH-52 | MUST/L1 | cfhreport-001 | kind=conformance_report; linter=accept | FAIL F6 |
| CFH-53 | MUST/L1 | cfhreport-001 | kind=conformance_report; linter=accept | FAIL F6 |
| CFH-54 | MUST NOT/L1 | cfhreport-001 | kind=conformance_report; linter=accept | FAIL F6 |

SHOULD-проверка: выбран весь набор, потому что он мал (n=5): TLP-3, TLP-4, TLP-8, TLP-15,
TLP-16. Все пять намеренно не входят в обязательный coverage и печатаются предупреждениями.
Новой непроверяемой обязанности среди них нет; требование brief о достаточности описаний для
малых моделей ими не выражено.

### Линза 4 — фазы плана между собой

Контракт-блоки P1–P10 проверены в порядке фаз: каждый потребляемый артефакт имеет более раннего
производителя; Required Reads существуют; Routing содержит каждый item и для P5.1 задаёт
frontier/xhigh manual; гейты сформулированы командами/литералами, а не словом «сделано».
Status Board, таблица P5 и handoff синхронизируются bookkeeping-коммитом этого захода.

Q-16 исполнена как детекция: текущий корпус содержит две golden-семьи, а P6.5 обязан выбрать
командное извлечение трёх остальных эталонов либо явно назвать дублированный литерал. Это
downstream-контракт, не причина красного предметного корпуса P4.

### Линза 5 — no-op, дыры и anti-regression

Семь дефектов старой ревизии перепроверены по написанному дереву:

| # | Проверка | Результат |
|--:|:--|:--|
| 1 | одно перечисление = один $defs | PASS, отдельные отрицательные artifact-кейсы |
| 2 | проза ↔ required/examples | PASS машинных проверок; семантика fixtures отдельно RED |
| 3 | ровно один L1/L2/L3 | PASS, 0 правил вне enum |
| 4 | двунаправленное обязательное покрытие | FORMAL PASS 382/382; SEMANTIC FAIL, 116 правил без годного носителя |
| 5 | description не создаёт норму | PASS отрицательного ovrdesc-кейса |
| 6 | cross-file refs offline | PASS fixtures-check/базового реального валидатора |
| 7 | финальное имя каталога, статус в README | PASS: 2026-08, draft |

Гейты перепрогнаны на c761a8e:

| Гейт | Команда/наблюдение | Итог |
|:--|:--|:--|
| A0 | SHA-256 vendor schema совпал с PIN: bd19a7a9e6ee3a7394aec816cd0660a0156864e828a348129baccdb83457d234; литерал ГЕЙТ A0: PASS ровно один | PASS |
| A1 | десять probe id присутствуют в DR-02; один литерал ГЕЙТ A1: PASS | PASS |
| A2 | spec_lint, rules_index --check = 399, close_schema --check = 15, legacy-check | PASS формального гейта |
| A3 | coverage --check = 382/382; fixtures-check = OK; 154 executable + 10 golden registrations | PASS формального гейта, не содержательности |

Q-27: детекция исполнена — измеримой нормы достаточности tool descriptions для малых моделей
в ревизии нет. Добавлять непроверяемый MUST нельзя; до freeze P3/владелец должны выбрать
измеримый критерий или явно снять это требование. Это major до решения.

### Линза 6 — лексика, термины и цена домена

Legacy-check чист. Сверка 11 документов с глоссарием не выявила конфликтующих определений;
перифразы не возвращают legacy-эры. Но утверждение D46 о механической цене смены домена
опровергнуто:

- grep -rl agentools\\.dev spec/revisions/2026-08 даёт 32 файла и 33 вхождения;
- объединение agentools.dev и dev.agentools даёт 35 файлов и 61 вхождение;
- носители agentools.dev: 01_extension-identity.md, README.md, schema/audit-event.json и
  14 остальных опубликованных schema-файлов, а также их 15 authoring-копий.

Полный перечень 32 файлов: spec/revisions/2026-08/01_extension-identity.md;
spec/revisions/2026-08/README.md; spec/revisions/2026-08/schema/audit-event.json;
spec/revisions/2026-08/schema/{clarification,confirmation,decision-audit,effect-registry,error,
extension-declaration,harness-protocol,idempotency,lifecycle-event,scenario,session-state,
side-effect,task-binding,tool-descriptor}.json и те же 15 имён под schema/authoring/.
Это больше «одно правило + один ключ профиля», поэтому по прямому if-then P5.1 — blocker,
адресат P3 и владелец. Производные схемы могут остаться механическими, но цена и команда
миграции должны быть описаны фактически, а не как два носителя.

## Находки

| ID | Линза | Severity | Файл:строка | failure_scenario | Адресат |
|:--|:--:|:--|:--|:--|:--|
| F1 | 3 | blocker | fixtures/2026-08/vectors/l1/security-001.json:6,58; spec/revisions/2026-08/10_security.md:73; 09_idempotency-errors.md:257 | Foreign access token обязан дать отдельный permission-отказ, но вектор ждёт confirmation_principal_mismatch производителя CNF-21; корректная реализация SEC-3 провалит корпус. | P4.5; если отдельный код невыразим — P3.11/P3.12 |
| F2 | 3 | blocker | fixtures/2026-08/schema/vector.json:63,165; vectors/l1/security-002.json:15; vectors/l3/audit-004.json:16; vectors/l2/longop-002.json:13 | Given/script не имеют носителей access token, provenance, principal/authorization, baggage, journal/effect state, tool result или task channel. Две реализации вынуждены выдумать разные скрытые stubs, а ожидаемый исход нельзя вывести из входа. | P4.1 + P4.2/P4.7 |
| F3 | 3 | blocker | tools/spec_lint.py:1115; fixtures/2026-08/static/extidentity-001.json:18; extnegotiation-001.json:17; toolcontract-001.json:17; tooldigest-001.json:17; toolauthoring-001.json:15; lifecycleindex-001.json:10 | Все шесть новых artifact-кейсов P4.5 либо кодируют неверный контракт (regex, spec_rev, annotations вместо extension, decimal_string), либо проверяют самоутверждённые boolean; правильный subject будет отвергнут, неправильный принят. | P4.5, нормативный текст не менять |
| F4 | 3 | blocker | fixtures/2026-08/vectors/l1/confpolicy-001.json:15; conftoken-001.json:24; lifecycleconcurrency-001.json:24; lifecyclefold-001.json:23; vectors/l2/clarificationstate-001.json:12; vectors/l3/confdual-001.json:19; lifecycleintegrity-001.json:23; profileuntrusted-001.json:8 | Все восемь новых behavior-кейсов P4.5 содержат незаконный descriptor/hash либо ожидают conflict/history/clarification/dual-control/provenance без соответствующего входа. Вместе F3+F4 делают 14/14 remediation-кейсов негодными и оставляют 78 sole-carrier MUST без проверки. | P4.1 + P4.5 |
| F5 | 3 | blocker | fixtures/2026-08/vectors/l2/scnorder-002.json:62,91; scnnocode-003.json:98,129; vectors/l2/scenario-004.json:12,35 | Generic action_failed либо обычный demo_probe с observed/limit не различают статический forward reference, запрет host-code или конкретный AST limit. Реализация, падающая по любой причине, проходит ожидание. | P4.7 |
| F6 | 3 | blocker | fixtures/2026-08/static/ovrdev-001.json:10; cfhbaseline-001.json:14; cfhreport-001.json:12; cfhgrease-001.json:11; tools/spec_lint.py:1088,1107,1184 | OVR-14 без требуемой фразы принимается; baseline без issue link принимается; report без per-vector records принимается; existence check выдаётся за every-run обеими реализациями. | P4.5/P4.8 |
| F7 | 6 | blocker | spec/revisions/2026-08/01_extension-identity.md:104; spec/revisions/2026-08/schema/: все 30 schema-профилей | Смена домена требует правки 32 файлов (35 для domain+vendor), а D46 обещает один rule + один profile key; owner получает заниженную цену и может заморозить неподконтрольный namespace. | P3 + владелец, затем раунд 2 P5.1 |
| F8 | 5 | major | plans/AGENTOOLS/open-questions.md:35; spec/revisions/2026-08/02_tool-contract-profile.md:20 | Инструмент с формально корректным, но бесполезным для малой модели description проходит весь контракт; требование brief не имеет измеримого адреса до freeze. | P3 + владелец; потребитель P10.6 |

Факт опровержения: F1 подтверждён разными производителями в единственной таблице кодов; F2 —
закрытым списком полей Given/ScriptStep; F3/F4 — прямой сверкой всех 14 файлов remediation
коммита f5a740e с правилами и checker branches; F5/F6 — частичным matcher и фактическими
subject/expect; F7 — воспроизводимыми grep-счётами; F8 — полным rules.json и реестром вопросов.
PLAUSIBLE-гипотез после опровержения не публикуется.

### Вменённая детекция

- Q-5: детекция исполнена — 32 файла с agentools.dev, 35 файлов для domain+vendor; утверждение
  D46 опровергнуто, F7 blocker.
- Q-16: детекция исполнена — две corpus golden-семьи покрывают JCS и idempotency; три остальных
  межъязыковых якоря должны извлекаться командой из PHP test data либо получить явно
  задокументированный второй литерал в P6.5.
- Q-27: детекция исполнена — измеримой нормы нет; граница названа F8 и в разделе ниже.

## что НЕ проверялось

- Behavior и harness не исполнялись: раннера P6 ещё нет. Проверена выводимость ожидания из
  формального входа, но не фактический runtime verdict. Это граница D26, не основание для PASS.
- Не проводились юридические проверки Q-5/Q-10 и не принималось owner decision.
- MAY-правила не выбирались для содержательного аудита; все пять SHOULD проверены целиком.
- Не проверялась эффективность описаний tools на конкретных моделях меньше 4B: нет измеримого
  критерия, что и зафиксировано F8/Q-27.
- Чужие runtime-кодовые базы не запускались; линза 2 проверяла их живые ADR/blueprints/modules.

## Перепрогнанные команды и наблюдаемые числа

| Команда на c761a8e | Наблюдение |
|:--|:--|
| python3 tools/spec_lint.py spec/revisions/2026-08 | OK; 5 SHOULD warnings |
| python3 tools/spec_lint.py spec/revisions/2026-08 --coverage --check | OK; MUST/MUST NOT 382/382 |
| python3 tools/spec_lint.py --fixtures-check fixtures/2026-08 | OK |
| python3 tools/spec_lint.py --legacy-check spec/revisions/2026-08 | OK |
| python3 tools/rules_index.py --check spec/revisions/2026-08 | OK, 399 |
| python3 tools/close_schema.py --check spec/revisions/2026-08 | OK, 15 |
| grep -rl agentools\\.dev spec/revisions/2026-08 | 32 файла |
| corpus inventory | behavior 93; artifact 50; harness 11; golden registrations 10 |

### Что запускать

РЕЖИМ: remediation закрытых P3/P4 по находкам раунда 1 Gate B, затем новый cold-start раунд P5.1
ВХОД: plans/AGENTOOLS/artifacts/P5-gate-b.md и findings/P5-adr-contours.md; прочитать F1–F8 и полную матрицу ПЕРВЫМИ
СКОУП: 1) P4.1 — расширить формальный driver state для provenance/principal/auth/baggage/journal/effect/tool-result/task-channel 2) P4.5/P4.7/P4.8 — заменить 32 негодных носителя различающими позитивно-негативными кейсами, исправить шесть static checker branches и повторить coverage/fixtures gates 3) P3.11/P3.12 — только если отдельный SEC-3 error code невыразим текущей таблицей 4) P3 + владелец — честная механика миграции 32/35 domain/vendor carriers и измеримый verdict Q-27 5) после зелёной remediation повторить task:plan-run AGENTOOLS P5.1 новым заходом
НЕ ТРОГАТЬ: DR-01/DR-02; frozen-history 2026-07-draft; живые ADR Flex/Axioma; P6–P11; freeze/tag/contracts P5.2 до GREEN
