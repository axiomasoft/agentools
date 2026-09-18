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
## Раунд 2 — 2026-08-04

**Вердикт: RED**

Предметный якорь P4.13: `2ffff1cc17d1f3a2f6f5bdd2526e0fdfb6f99b32`. Перепрогон
выполнен на текущем `e232fc548d8ee52b9b33779425345e5cc412b399`; `git diff
2ffff1c..e232fc5 -- spec/revisions/2026-08 fixtures/2026-08 tools/spec_lint.py` пуст, то есть
последующие PHP-коммиты не меняют проверяемые байты спеки, корпуса и линтера.

### Почему RED

Формальная relation-aware карта P4.13 проходит: exact 27 исходных carriers, 116 round-1 FAIL
rules и CFH-55 pair. Содержательная проверка каждой строки карты не проходит. Из 116
ремедиированных обязательных правил 51 всё ещё не имеет годного различающего носителя; 65
прошли. Вместе с 266 неизменёнными строками раунда 1 и новым CFH-55 текущая полная матрица
даёт 332 PASS и 51 FAIL из 383 тестируемых MUST/MUST NOT.

Преобладающий дефект — не отсутствие input-diff, а подмена причины исхода самоутверждённым
полем: `write_back_upcast`, `source_of_truth`, `hash_input_includes_prev_hash`, `chain_valid`,
`reject_passing_entries`, `grease_allowed` или диагностическим payload, который SUT может
напечатать без требуемого поведения. Корректная реализация, не читающая эти ненормированные
флаги, провалит корпус; мутант, ветвящийся по ним, пройдёт. Это blocker по прямой таксономии
Gate B: freeze закрепил бы неверный conformance-контракт.

### Линза 1 — спека ↔ решения

Повторная сверка DR-01 §A–§L и DR-02 против 12 документов не нашла нового противоречия в
нормативном тексте. D142/D147/D149/D159 выражены: отдельный SEC-3 code существует, открытые
enum fail-closed, CFH-55 добавлен, pair relation формализован. Именно граница D159 остаётся
существенной: relation доказывает реальный JSON-diff, но не доказывает, что pointer является
нормативным stimulus правила. Решения не дают права считать self-asserted payload поведением.

### Линза 2 — спека ↔ ADR контуров

Живые каноны перечитаны на текущих якорях: Axioma `43500e83`, CoreX `7545ef41`, Flex
`80182e8f`, tracked vault-копии ADR-020/B-13/B-16 — `55edccec` (последний коммит выбранных
blueprints `88109c9d`). Контрактных конфликтов не найдено. MCP-only, Finance immutability,
Tier/side-effect, AiGateway, ToolRegistry, user×agent permissions, Reverb delivery и HITL
требуют адаптеров, но не нового поля провода. Датированная таблица раунда 2 дописана в
`findings/P5-adr-contours.md`.

### Линза 3 — правила ↔ векторы содержательно

Полная текущая энумерация задана без переписывания истории: 266 строк раунда 1, не входящих в
`gate-b-remediation.json.round1_fail_rules`, сохраняют свои carrier/predicate/PASS; таблица ниже
заменяет все 116 remediated-строк и добавляет CFH-55. Поэтому правило чтения матрицы
однозначно: delta-строка раунда 2 имеет приоритет, отсутствие delta означает неизменённый PASS
раунда 1. Ни один MUST/MUST NOT не выбран выборкой.

| Маркер | Текущий carrier | Заявленный различающий признак | Аудит раунда 2 |
|:--|:--|:--|:--|
| OVR-14 | ovrdev-001, ovrdev-002 | artifact `rule_deviation_note`; accept ↔ reject:фразу-обоснование | PASS |
| EXT-1 | extidentity-001 | artifact `extension_identity`; accept | PASS |
| EXT-2 | extidentity-001 | artifact `extension_identity`; accept | PASS |
| EXT-3 | extidentity-001 | artifact `extension_identity`; accept | PASS |
| EXT-4 | extidentity-001 | artifact `extension_identity`; accept | PASS |
| EXT-5 | extidentity-001 | artifact `extension_identity`; accept | PASS |
| EXT-6 | extidentity-001, extidentity-002 | artifact `extension_identity`; accept ↔ reject:identity расширения | PASS |
| EXT-8 | extidentity-001 | artifact `extension_identity`; accept | PASS |
| EXT-9 | extidentity-001 | artifact `extension_identity`; accept | PASS |
| EXT-11 | extnegotiation-001 | artifact `extension_negotiation`; accept | PASS |
| EXT-12 | extnegotiation-001 | artifact `extension_negotiation`; accept | PASS |
| EXT-13 | extnegotiation-001 | artifact `extension_negotiation`; accept | PASS |
| EXT-14 | extnegotiation-001, extnegotiation-002 | artifact `extension_negotiation`; accept ↔ reject:negotiation расширения | PASS |
| EXT-15 | extnegotiation-001 | artifact `extension_negotiation`; accept | PASS |
| EXT-16 | extnegotiation-001 | artifact `extension_negotiation`; accept | PASS |
| EXT-17 | extnegotiation-001 | artifact `extension_negotiation`; accept | PASS |
| TLP-1 | toolcontract-001 | artifact `tool_contract_case`; accept | PASS |
| TLP-2 | toolcontract-001, toolcontract-002, tooldescription-001, tooldescription-002 | artifact `tool_contract_case,tool_description`; accept ↔ reject:actionable contract ↔ accept ↔ reject:actionable contract | PASS |
| TLP-5 | toolcontract-003, toolcontract-004 | artifact `tool_result_case`; accept ↔ reject:structured result | PASS |
| TLP-6 | toolcontract-001 | artifact `tool_contract_case`; accept | PASS |
| TLP-7 | toolcontract-001 | artifact `tool_contract_case`; accept | FAIL F9 |
| TLP-9 | toolcontract-001 | artifact `tool_contract_case`; accept | PASS |
| TLP-10 | toolcontract-001 | artifact `tool_contract_case`; accept | PASS |
| TLP-11 | confpolicy-001 ↔ confpolicy-101 | value `/given/tools/0/_meta/dev.agentools~1action/confirmation`; failed/action_failed → failed/action_failed | FAIL F9 |
| TLP-12 | toolcontract-001 | artifact `tool_contract_case`; accept | FAIL F9 |
| TLP-17 | tooldigest-001, tooldigest-002 | artifact `descriptor_digest_case`; accept ↔ reject:пяти членов | PASS |
| TLP-18 | tooldigest-001 | artifact `descriptor_digest_case`; accept | PASS |
| TLP-19 | tooldigest-001 | artifact `descriptor_digest_case`; accept | PASS |
| TLP-20 | tooldigest-001 | artifact `descriptor_digest_case`; accept | PASS |
| TLP-21 | tooldigest-001 | artifact `descriptor_digest_case`; accept | PASS |
| TLP-22 | tooldigest-001 | artifact `descriptor_digest_case`; accept | PASS |
| TLP-23 | tooldigest-001 | artifact `descriptor_digest_case`; accept | PASS |
| TLP-25 | profileuntrusted-001 ↔ profileuntrusted-101 | value `/given/driver_state/provenance/0/content_origin`; completed/confirmation_resolved,action_completed → completed/confirmation_resolved,action_completed | FAIL F9 |
| TLP-26 | toolauthoring-001, toolauthoring-002 | artifact `authoring_schema_profile`; accept ↔ reject:authoring schema profile | FAIL F10 |
| TLP-27 | toolauthoring-001 | artifact `authoring_schema_profile`; accept | FAIL F10 |
| TLP-28 | toolauthoring-001 | artifact `authoring_schema_profile`; accept | FAIL F10 |
| TLP-30 | toolauthoring-001 | artifact `authoring_schema_profile`; accept | FAIL F10 |
| TLP-31 | toolauthoring-001 | artifact `authoring_schema_profile`; accept | FAIL F10 |
| TLP-32 | toolauthoring-001 | artifact `authoring_schema_profile`; accept | FAIL F10 |
| ALC-2 | lifecyclefold-001 ↔ lifecyclefold-101 | value `/script/1/llm_stub/toolCall/args/value`; failed/action_failed → completed/action_completed | FAIL F11 |
| ALC-3 | lifecyclefold-002 ↔ lifecyclefold-102 | value `/script/1/llm_stub/toolCall/args/value`; failed/action_failed → completed/action_completed | FAIL F11 |
| ALC-4 | lifecyclefold-003 ↔ lifecyclefold-103 | value `/given/driver_state/journal/0/payload/projection_source`; failed/action_failed → completed/action_completed | FAIL F11 |
| ALC-6 | lifecyclefold-004 ↔ lifecyclefold-104 | value `/given/driver_state/journal/0/seq`; failed/action_failed → completed/action_created | PASS |
| ALC-7 | lifecyclefold-005 ↔ lifecyclefold-105 | value `/given/driver_state/journal/0/ts`; failed/action_failed → completed/action_started | PASS |
| ALC-14 | lifecycleindex-001, lifecycleindex-002 | artifact `lifecycle_index`; accept ↔ reject:не синхронизировано | FAIL F10 |
| ALC-15 | lifecyclefold-006 ↔ lifecyclefold-106 | value `/given/driver_state/journal/0/kind`; failed/action_failed → completed/vendor.known | FAIL F11 |
| ALC-16 | lifecyclefold-007 ↔ lifecyclefold-107 | value `/given/driver_state/journal/0/kind`; failed/action_failed → failed/action_failed | PASS |
| ALC-18 | lifecyclefold-008 ↔ lifecyclefold-108 | value `/script/1/llm_stub/toolCall/args/value`; failed/action_failed → completed/action_completed | FAIL F11 |
| ALC-19 | lifecycleconcurrency-001 ↔ lifecycleconcurrency-101 | value `/script/1/llm_stub/toolCall/args/value`; failed/action_failed → completed/effect_requested | FAIL F11 |
| ALC-20 | lifecycleconcurrency-002 ↔ lifecycleconcurrency-102 | value `/given/driver_state/journal/1/expected_prev_seq`; failed/action_failed → completed/action_started | FAIL F11 |
| ALC-21 | lifecycleconcurrency-003 ↔ lifecycleconcurrency-103 | value `/given/driver_state/journal/1/expected_prev_seq`; failed/action_failed → completed/action_started | PASS |
| ALC-22 | lifecycleconcurrency-004 ↔ lifecycleconcurrency-104 | value `/given/driver_state/journal/1/expected_prev_seq`; failed/action_failed → completed/action_started | FAIL F11 |
| ALC-23 | lifecycleconcurrency-005 ↔ lifecycleconcurrency-105 | value `/given/driver_state/journal/1/expected_prev_seq`; failed/action_failed → completed/effect_requested | FAIL F11 |
| ALC-25 | lifecycleconcurrency-006 ↔ lifecycleconcurrency-106 | value `/given/driver_state/effects/0/effects/demo_probe:record-1/status`; completed/effect_confirmed → completed/effect_requested | PASS |
| ALC-26 | lifecycleconcurrency-007 ↔ lifecycleconcurrency-107 | value `/given/driver_state/journal/0/payload/epoch`; failed/action_failed → completed/epoch_started | FAIL F11 |
| ALC-27 | lifecycleconcurrency-008 ↔ lifecycleconcurrency-108 | value `/given/driver_state/journal/1/epoch`; failed/action_failed → completed/effect_requested | PASS |
| ALC-28 | lifecycleintegrity-001 ↔ lifecycleintegrity-101 | value `/script/1/llm_stub/toolCall/meta/idempotency_key`; failed/action_failed → completed/action_completed | FAIL F11 |
| ALC-30 | lifecycleintegrity-002 ↔ lifecycleintegrity-102 | value `/given/driver_state/journal/0/event_version`; failed/action_failed → completed/action_completed | FAIL F11 |
| ALC-31 | lifecycleintegrity-003 ↔ lifecycleintegrity-103 | value `/given/driver_state/journal/0/payload/write_back_upcast`; failed/action_failed → completed/action_completed | FAIL F11 |
| ALC-33 | lifecycleintegrity-004 ↔ lifecycleintegrity-104 | value `/given/driver_state/journal/0/payload/snapshot/spec_rev`; failed/action_failed → completed/action_completed | FAIL F11 |
| ALC-34 | lifecycleintegrity-005 ↔ lifecycleintegrity-105 | value `/given/driver_state/journal/0/payload/snapshot/source_of_truth`; failed/action_failed → completed/action_completed | FAIL F11 |
| ALC-35 | lifecycleintegrity-006 ↔ lifecycleintegrity-106 | presence `/given/driver_state/journal/0/prev_hash`; failed/action_failed → completed/action_completed | FAIL F11 |
| ALC-36 | lifecycleintegrity-007 ↔ lifecycleintegrity-107 | value `/given/driver_state/journal/0/payload/hash_input_includes_prev_hash`; failed/action_failed → completed/action_completed | FAIL F11 |
| ALC-37 | lifecycleintegrity-008 ↔ lifecycleintegrity-108 | value `/given/driver_state/journal/1/payload/chain_valid`; failed/action_failed → completed/action_completed | FAIL F11 |
| CNF-3 | confpolicy-002 ↔ confpolicy-102 | value `/given/policy/rules/0/verdict`; failed/action_failed → completed/confirmation_resolved,action_completed | FAIL F12 |
| CNF-4 | confpolicy-003 ↔ confpolicy-103 | value `/given/policy/rules/0/verdict`; failed/action_failed → completed/confirmation_resolved,action_completed | PASS |
| CNF-5 | confpolicy-004 ↔ confpolicy-104 | value `/given/policy/rules/0/verdict`; failed/action_failed → completed/confirmation_resolved,action_completed | PASS |
| CNF-6 | confpolicy-005 ↔ confpolicy-105 | value `/given/policy/rules/0/verdict`; failed/action_failed → completed/confirmation_resolved,action_completed | FAIL F12 |
| CNF-8 | confpolicy-006 ↔ confpolicy-106 | value `/given/policy/rules/0/verdict`; failed/action_failed → completed/confirmation_resolved,action_completed | FAIL F12 |
| CNF-13 | conftoken-001 ↔ conftoken-101 | value `/given/driver_state/confirmation/proposal_version`; failed/action_failed → completed/confirmation_resolved,action_completed | PASS |
| CNF-21 | conftoken-002 ↔ conftoken-102 | value `/given/driver_state/confirmation/covered_principal_id`; failed/action_failed → completed/confirmation_resolved,action_completed | PASS |
| CNF-23 | conftoken-003 ↔ conftoken-103 | value `/given/driver_state/confirmation/current_descriptor_digest`; failed/action_failed → completed/confirmation_resolved,action_completed | PASS |
| CNF-33 | confdual-001 ↔ confdual-101 | value `/given/driver_state/principals/authorizer/id`; failed/action_failed → completed/confirmation_resolved,action_completed | PASS |
| CNF-34 | confdual-002 ↔ confdual-102 | value `/given/driver_state/principals/authorizer/id`; failed/action_failed → completed/confirmation_resolved,action_completed | PASS |
| CNF-35 | confpolicy-007 ↔ confpolicy-107 | length `/given/driver_state/authorization/permissions`; failed/action_failed → completed/confirmation_resolved,action_completed | PASS |
| CNF-36 | confpolicy-008 ↔ confpolicy-108 | length `/given/driver_state/authorization/permissions`; failed/action_failed → completed/confirmation_resolved,action_completed | PASS |
| CNF-38 | conftoken-004 ↔ conftoken-104 | value `/given/driver_state/confirmation/commit_args_fingerprint`; failed/action_failed → completed/confirmation_resolved,action_completed | PASS |
| CLR-2 | clarificationstate-001 ↔ clarificationstate-101 | value `/script/2/task_message/status`; running/input_recorded → completed/task_status_observed,action_completed | FAIL F12 |
| CLR-8 | clarificationstate-002 ↔ clarificationstate-102 | value `/script/2/task_message/status`; running/input_recorded → completed/task_status_observed,action_completed | FAIL F12 |
| SCN-9 | scenario-002, scnforward-013, scnorder-002 | artifact `behavior`; failed ↔ completed ↔ failed | PASS |
| AOB-25 | audit-004 ↔ audit-104 | value `/given/driver_state/audit_export/mapping_version`; failed/action_failed → completed/action_completed | FAIL F13 |
| AOB-26 | auditschema-001, auditschema-002 | artifact `audit_schema`; reject:audit_schema ↔ accept | PASS |
| AOB-27 | audit-006 ↔ audit-106 | value `/given/driver_state/audit_export/attributes/0`; failed/action_failed → completed/action_completed | PASS |
| AOB-28 | audit-007 ↔ audit-107 | value `/given/driver_state/audit_export/attributes/0`; failed/action_failed → completed/action_completed | PASS |
| AOB-29 | audit-008 ↔ audit-108 | value `/given/driver_state/baggage/0/value`; failed/action_failed → completed/action_completed | FAIL F13 |
| AOB-30 | audit-009 ↔ audit-109 | value `/given/driver_state/baggage/0/value`; failed/action_failed → completed/action_completed | FAIL F13 |
| AOB-31 | audit-010 ↔ audit-110 | length `/given/driver_state/baggage`; failed/audit.limit_exceeded → completed/action_completed | FAIL F13 |
| SEC-1 | security-001 ↔ security-101 | value `/given/driver_state/credentials/0/audience`; failed/action_failed → completed/action_completed | PASS |
| SEC-2 | security-002 ↔ security-102 | value `/given/driver_state/credentials/0/integrity`; failed/action_failed → completed/action_completed | PASS |
| SEC-3 | security-003 ↔ security-103 | value `/given/driver_state/credentials/0/audience`; failed/action_failed → completed/action_completed | PASS |
| SEC-4 | security-004 ↔ security-104 | value `/given/driver_state/credentials/0/audience`; failed/action_failed → completed/action_completed | FAIL F14 |
| SEC-5 | security-005 ↔ security-105 | value `/given/driver_state/credentials/0/source`; failed/action_failed → completed/action_completed | PASS |
| SEC-6 | security-006 ↔ security-106 | value `/given/driver_state/credentials/0/kind`; failed/action_failed → completed/action_completed | PASS |
| SEC-7 | security-007 ↔ security-107 | value `/given/driver_state/provenance/0/content_origin`; failed/action_failed → completed/action_completed | PASS |
| SEC-8 | security-008 ↔ security-108 | value `/given/driver_state/provenance/0/content_origin`; failed/action_failed → completed/action_completed | PASS |
| SEC-9 | security-009 ↔ security-109 | value `/given/driver_state/provenance/0/content_origin`; failed/action_failed → completed/action_completed | FAIL F14 |
| SEC-10 | security-010 ↔ security-110 | value `/given/driver_state/provenance/0/content_origin`; failed/action_failed → completed/action_completed | FAIL F14 |
| SEC-11 | security-011 ↔ security-111 | presence `/given/tools/0/_meta/dev.agentools~1action/confirmation`; failed/action_failed → completed/action_completed | PASS |
| SEC-12 | security-012 ↔ security-112 | value `/given/driver_state/provenance/0/content_origin`; failed/action_failed → completed/action_completed | FAIL F14 |
| SEC-13 | security-013 ↔ security-113 | value `/given/driver_state/principals/agent/id`; failed/action_failed → completed/action_completed | FAIL F14 |
| SEC-14 | security-014 ↔ security-114 | value `/given/driver_state/principals/agent/id`; failed/action_failed → completed/action_completed | FAIL F14 |
| SEC-15 | security-015 ↔ security-115 | value `/given/driver_state/principals/initiator/id`; failed/action_failed → completed/action_completed | FAIL F14 |
| SEC-16 | security-016 ↔ security-116 | value `/given/driver_state/authorization/resource`; failed/action_failed → completed/action_completed | FAIL F14 |
| SEC-17 | securityauthoring-001, securityauthoring-002 | artifact `security_authoring`; reject:security_authoring ↔ accept | PASS |
| SEC-18 | security-018 ↔ security-118 | value `/given/driver_state/confirmation/token_id`; failed/action_failed → completed/action_completed | FAIL F14 |
| SEC-19 | security-019 ↔ security-119 | length `/given/driver_state/authorization/permissions`; failed/action_failed → completed/action_completed | PASS |
| CFH-22 | cfhbaseline-001 | artifact `known_failures`; accept | PASS |
| CFH-23 | cfhbaseline-001 | artifact `known_failures`; accept | PASS |
| CFH-24 | cfhbaseline-001 | artifact `known_failures`; accept | FAIL F15 |
| CFH-25 | cfhbaseline-001, cfhbaseline-002 | artifact `known_failures`; accept ↔ reject:issue-link | PASS |
| CFH-26 | cfhbaseline-001 | artifact `known_failures`; accept | FAIL F15 |
| CFH-50 | cfhgrease-001, cfhgrease-002 | artifact `grease_registration`; accept ↔ reject:три неизвестных | PASS |
| CFH-51 | cfhgrease-001 | artifact `grease_registration`; accept | FAIL F15 |
| CFH-52 | cfhreport-001, cfhreport-002 | artifact `conformance_report`; accept ↔ reject:per-vector records | PASS |
| CFH-53 | cfhreport-001 | artifact `conformance_report`; accept | FAIL F15 |
| CFH-54 | cfhreport-001 | artifact `conformance_report`; accept | PASS |
| CFH-55 | hmcpcode-001 ↔ hmcpcodebad-001 | exact `mcp_errors`: `-32003/-32004` ↔ `-32002/-32004`; conformant ↔ violation CFH-55 | PASS |

Все пять SHOULD (`TLP-3`, `TLP-4`, `TLP-8`, `TLP-15`, `TLP-16`) проверены целиком, а не
выборкой. Они по-прежнему намеренно не входят в mandatory coverage и дают пять warnings; новой
обязанности, способной закрыть перечисленные FAIL, в них нет.

### Линза 4 — фазы плана между собой

Контракт-блоки P1–P10 и Required Reads по-прежнему имеют производителей и существующие пути;
Routing P5.1 = `frontier/xhigh`, `manual`. Но фактическая последовательность и Status Board
разошлись: при P5 `Blocked/In progress`, P6 `Not started` и P7 `0/12 Not started` в текущей ветке
уже лежат одиннадцать PHP item-коммитов P7.1–P7.10 (`b11cf41`…`e232fc5`). Это не правилось
аудитом, но названо F16: status-driven resume теперь способен повторно выполнить уже
закоммиченный scope, а реализация строится против не прошедшего freeze контракта.

Общий свип `open-questions.md` выявил F17: Q-28 остаётся `Decision pending`, владелец — закрытая
P4, хотя текст уже принял boundary «scenario cancellation не нормируется». Перед freeze реестр
должен либо зафиксировать этот принятый исход, либо получить явное owner decision; текущая
строка не имеет исполнимого будущего адресата.

### Линза 5 — no-op, дыры и anti-regression

Семь исторических anti-regression классов формально закрыты: один носитель enum, prose↔schema,
ровно один level, двунаправленный coverage, description без нормы, offline refs и неизменяемое
имя каталога. Семантическая часть anti-regression №4 остаётся красной: 383/383 ссылки есть, но
51 sole-carrier rule не может отличить корректную реализацию от мутанта.

Перепрогон гейтов на текущем дереве:

| Гейт | Наблюдение | Итог |
|:--|:--|:--|
| A0 | vendor SHA-256 `bd19a7a9…d234` совпадает с PIN; один `ГЕЙТ A0: PASS` | PASS |
| A1 | один `ГЕЙТ A1: PASS`; p01…p10 присутствуют; Q-9 не pending | PASS |
| A2 | full spec lint OK с 5 SHOULD warnings; rules_index 400; close_schema 15; legacy OK | PASS формального гейта |
| A3 | coverage 383/383; fixtures OK; Gate-B remediation exact 27/116 + mutation rejects + CFH-55 | PASS формального гейта, SEMANTIC RED |

Q-16 детектирован: CFH-38…CFH-44 и `golden_case` выражают fixed input → expected bytes, а D98
разрешает извлекать остальные три anchors командой из PHP test data. Структурного blocker до
freeze нет; вопрос честно остаётся Exploration до реализации P6.5.

### Линза 6 — лексика, термины и цена домена

Legacy-check чист; возврата legacy-эры или конфликтующего глоссария не найдено. Round-1 F7
закрыт решениями D144 и P3.17: владелец подтвердил `agentools.dev`, а цена больше не заявлена как
два файла — inventory стабильно даёт 32 domain files и 35 domain+vendor files. Это измеренная
цена принятого namespace, не текущий blocker.

## Находки раунда 2

| ID | Линза | Severity | Файл:строка | failure_scenario | Адресат |
|:--|:--:|:--|:--|:--|:--|
| F9 | 3 | blocker | `02_tool-contract-profile.md` TLP-7/TLP-11/TLP-12/TLP-25; `toolcontract-001`; `confpolicy-001/101`; `profileuntrusted-001/101` | Корректная реализация игнорирует `confirmation` как источник класса эффекта и реально изолирует untrusted route, но пары требуют разного self-reported payload; реализация без изоляции, печатающая `execution_route=isolated`, проходит. Уникальность имён и закрытость action-block не проверяются. | P4 carrier/checker; owner после лимита раундов |
| F10 | 3 | blocker | `tools/spec_lint.py:1806-1821`; `toolauthoring-001`; `lifecycleindex-001` | Published schema с `unevaluatedProperties:false`, голым open-enum, tuple либо exponent проходит `authoring_schema_profile`, потому что branch смотрит только `additionalProperties`; кандидат, вписанный в self-provided `matrix_rows`, проходит без сверки реальной transition table. | P4 checker/artifact carriers |
| F11 | 3 | blocker | `lifecyclefold-*`, `lifecycleconcurrency-*`, `lifecycleintegrity-*`; ALC-2/3/4/15/18/19/20/22/23/26/28/30/31/33–37 | Корректный reducer не читает ненормированные `projection_source`, `write_back_upcast`, `source_of_truth`, `hash_input_includes_prev_hash`, `chain_valid`; поэтому он не выдаёт предписанный fixture failure. Мутант, ветвящийся только по этим payload flags/строкам `deterministic`, проходит. | P4 behavior carriers/driver contract |
| F12 | 3 | blocker | `confpolicy-002..006/102..106`; `clarificationstate-001/101/002/102` | Реализация, clamping `allow` к обязательному irreversible-gate, соответствует CNF-6, но vector требует отказ; CNF-8 self-reports layer ownership без input о mechanics. CLR-2/CLR-8 меняют task status, не opaque MRTR state и не запрещённую пятую точку `input_required`. | P4 behavior carriers |
| F13 | 3 | blocker | `audit-004/104`, `audit-008/108`, `audit-009/109`, `audit-010/110`; AOB-25/AOB-29/AOB-30/AOB-31 | Implementation получает raw `tenant:acme` без формального opaque/PII classifier и не может вывести fixture verdict; mapping_version не имеет declared current value, а AOB-31 проверяет 64/65 items, но не обязательный 8192-byte boundary. | P4 driver contract + behavior carriers |
| F14 | 3 | blocker | `security-004/104`, `009/109`, `010/110`, `012/112`, `013..016`, `018`; SEC-4/9/10/12–16/18 | SEC-4 pair считает downstream-audience token запрещённым, а runtime-audience допустимым без моделирования downstream credential; SEC-9 provenance указывает `args.derived_value`, которого нет в tool args; остальные пары выводят carrier/type из магических строк `runtime-daemon`, `session:*`, `audit-record-*` либо не наблюдают required actor/payload. Корректная typed implementation провалит ожидания. | P4 behavior carriers/typed driver inputs |
| F15 | 3 | blocker | `tools/spec_lint.py:1701-1757,1824-1831`; `cfhbaseline-001`; `cfhgrease-001`; `cfhreport-001`; CFH-24/26/51/53 | Runner с healed baseline entry и ни разу не запущенным GREASE проходит artifact checker: он доверяет booleans `reject_passing_entries`/`grease_allowed` и видит регистрацию, не every-run обеими реализациями. Report без явного перечня failed L3 также принят. | P4 harness/artifact carriers; P6 boundary |
| F16 | 4 | major | git `b11cf41..e232fc5`; `plan.md` Status Board; `phases/P7.md:144-153` | Новый executor читает P7 как 0/12 Not started и повторяет/перетирает scope, хотя одиннадцать P7-коммитов уже в ветке; одновременно код пинится к contract до GREEN/freeze. | owner + P7 bookkeeping; код этим аудитом не трогать |
| F17 | 4/5 | major | `open-questions.md:36` Q-28; `07_long-operations.md` LOP-26 | Freeze может пройти с `Decision pending`, владельцем которого остаётся уже terminal P4: реестр не различает «boundary принят» и «решение ещё нужно», поэтому обязательство не имеет исполнимого адресата. | owner; при смене boundary — P3 новой ревизией |

Факты опровержения: F9–F15 подтверждены прямым сравнением нормативной строки, pair JSON и
реального checker branch, а не отсутствием runtime; F16 — commit graph + текущими Status Board и
Phase Status; F17 — дословной строкой реестра и уже существующим boundary LOP-26. PLAUSIBLE-
гипотез после опровержения не публикуется.

### Вменённая детекция — раунд 2

- Q-16: детекция исполнена — механизм умеет fixed input → expected bytes; три некорпусных
  anchors остаются за командным извлечением P6.5/D98. В текущем реестре это единственная строка,
  чей текст содержит `P5.1`.
- Общий свип незакрытых вопросов исполнен; Q-28 опубликован отдельной major-находкой F17.

## что НЕ проверялось — раунд 2

- Behavior/harness по-прежнему не исполнялись общим conformance runner: фаза P6 по плану не
  начата. Проверена выводимость oracle из формального input и способность carrier убить мутант.
- PHP-код коммитов P7.1–P7.10 не ревьюился и не тестировался: он вне Files/Scope P5.1; проверен
  только факт его существования и расхождение со статусом.
- Юридические owner-гейты Q-10/Q-30/Q-31 не решались.
- MAY-правила не аудировались; SHOULD проверены все пять.
- Чужие runtime-кодовые базы не запускались; линза 2 читала живые canons read-only.

## Перепрогнанные команды и наблюдаемые числа — раунд 2

| Команда | Наблюдение |
|:--|:--|
| `python3 tools/spec_lint.py --gate-b-remediation-check fixtures/2026-08` | OK; exact 27 carriers / 116 rules; 6 F-mutations + relation mutations + CFH-55 reject |
| `python3 tools/spec_lint.py --fixtures-check fixtures/2026-08` | OK |
| `python3 tools/spec_lint.py spec/revisions/2026-08` | OK; 5 SHOULD warnings |
| `python3 tools/spec_lint.py spec/revisions/2026-08 --coverage --check` | OK; MUST/MUST NOT 383/383 |
| `python3 tools/spec_lint.py --legacy-check spec/revisions/2026-08` | OK |
| `python3 tools/rules_index.py --check spec/revisions/2026-08` | OK; 400 rules |
| `python3 tools/close_schema.py --check spec/revisions/2026-08` | OK; 15 schemas |
| corpus inventory | behavior 214; artifact 68; harness 13; golden registrations 10 |
| domain inventory | 32 domain files; 35 domain+vendor files |

Append-only правило раундов создаёт в целом файле два verdict literals и двенадцать заголовков
линз; scoped-проверка блока `## Раунд 2` даёт ровно 1 verdict и 6 lenses. Это процессное
несогласование буквальных whole-file grep из Validation с правилом «не переписывать прежний
раунд», а не основание удалить историю.

### Что запускать — раунд 2

РЕЖИМ: owner gate после Gate B round 2 = RED; третий аудит запрещён лимитом раундов
ВХОД: `artifacts/P5-gate-b.md` F9–F17, delta-матрица 116 правил, `findings/P5-adr-contours.md`
СКОУП: владелец выбирает, переоткрывать ли P3/P4 для настоящих observational carriers и синхронизации plan truth либо менять контракт тестируемости новой ревизией; только после решения допускается новый плановый маршрут
НЕ ТРОГАТЬ: DR-01/DR-02 и историю round 1/2; `spec/revisions/2026-07-draft`; freeze/tag P5.2; существующие PHP-коммиты P7 без отдельного owner-approved решения

## Раунд 3 — 2026-08-04

**Вердикт: GREEN**

Якорь проверенного source-дерева observational remediation: `a12db23`; bookkeeping-якорь
закрытой P4.21: `dc6990f`. Раунд продолжает один ранее открытый слот: промежуточный дефект CLR-8
был найден до публикации verdict, адресно исправлен P4.21 и только затем аудит возобновлён.

### Линза 1 — решения DR-01/DR-02 ↔ ревизия

Полная round-1 матрица решений повторно сверена с текущими 400 правилами. Адреса §A–§J и
решений DR-02 сохранились; remediation P4.16–P4.21 не меняла normative bytes P3 (digest source-
дерева P3 `24ce17d…300215`). Пересмотры §J9/§E5/§J6, ручные DTO и owner-gate DR-02 §6 не
получили висящих либо противоречащих формулировок. Решений без нормативного адреса нет.

### Линза 2 — ревизия ↔ живые ADR контуров

Живая сверка опубликована в `findings/P5-adr-contours.md`, раунд 3. Якоря Axioma/CoreX/Flex/vault:
`43500e8`, `7545ef4`, `80182e8`, `55edcce`. Конфликтов нет; для tier/stake, principal,
provenance и gateway boundaries по-прежнему нужен адаптер P9/P10, но он не ослабляет контурные
authorization/provider paths и не вводит второй carrier. Shared-memory результат из-за scope-
mismatch использован только как подсказка; доказательство основано на живых канонических файлах.

### Линза 3 — MUST/MUST NOT ↔ различающие carriers

В `rules.json` 389 обязательных правил: 383 testable покрыты 383/383; шесть явно
`not_testable` — `LOP-9`, `IDE-44`, `IDE-45`, `IDE-46`, `IDE-47`, `IDE-48`. Все 332 обязательных правила вне F9–F15 повторно
прошли прежнюю полную матрицу раундов 1/2. Для спорных 51 правил проверены одновременно: сырой
stimulus либо независимый checker-subject, driver-owned observation, парный oracle и адресный
мутант. `gaps=[]`, запрещённых shortcuts 0; 51/51 адресных и 11/11 межправильных semantic
mutants отвергнуты. Полная exact-51 перекличка следует ниже.

| Правило | Пара carriers | Stimulus → observation | Мутант |
|:--|:--|:--|:--|
| ALC-14 | lifecycleindex-001/002 | raw index → checker-derived index | rejected |
| ALC-15 | lifecyclefold-006/106 | journal entry → projection | rejected |
| ALC-18 | lifecyclefold-008/108 | journal entry → projection + tool boundary | rejected |
| ALC-19 | lifecycleconcurrency-001/101 | raw append attempt → projection | rejected |
| ALC-2 | lifecyclefold-001/101 | journal entry → projection + tool boundary | rejected |
| ALC-20 | lifecycleconcurrency-002/102 | append adapter conflict → projection | rejected |
| ALC-22 | lifecycleconcurrency-004/104 | append adapter conflict → projection | rejected |
| ALC-23 | lifecycleconcurrency-005/105 | append adapter conflict → projection | rejected |
| ALC-26 | lifecycleconcurrency-007/107 | raw append attempt → projection | rejected |
| ALC-28 | lifecycleintegrity-001/101 | journal bytes → projection | rejected |
| ALC-3 | lifecyclefold-002/102 | journal entry → projection | rejected |
| ALC-30 | lifecycleintegrity-002/102 | journal bytes → projection | rejected |
| ALC-31 | lifecycleintegrity-003/103 | journal bytes → projection | rejected |
| ALC-33 | lifecycleintegrity-004/104 | journal bytes → projection | rejected |
| ALC-34 | lifecycleintegrity-005/105 | journal bytes → projection | rejected |
| ALC-35 | lifecycleintegrity-006/106 | journal bytes → projection | rejected |
| ALC-36 | lifecycleintegrity-007/107 | three-event chain → projection | rejected |
| ALC-37 | lifecycleintegrity-008/108 | journal bytes → projection | rejected |
| ALC-4 | lifecyclefold-003/103 | journal entry → projection | rejected |
| AOB-25 | audit-004/104 | mapping registry membership → audit sink | rejected |
| AOB-29 | audit-008/108 | raw audit input → audit sink | rejected |
| AOB-30 | audit-009/109 | raw audit input → audit sink | rejected |
| AOB-31 | audit-010/110 | 8192/8195 wire bytes → audit sink | rejected |
| CFH-24 | cfhbaseline-001/101 | every-run baseline subject → checker | rejected |
| CFH-26 | cfhbaseline-003/103 | healed-entry subject → checker | rejected |
| CFH-51 | cfhgrease-001/101 | GREASE registration/run subject → checker | rejected |
| CFH-53 | cfhreport-001/101 | failed-L3 subject → checker | rejected |
| CLR-2 | clarificationstate-001/101 | opaque protocol state → task binding | rejected |
| CLR-8 | clarificationstate-002/102 | exact request type → task binding | rejected |
| CNF-3 | confpolicy-002/102 | policy input → tool boundary | rejected |
| CNF-6 | confpolicy-005/105 | irreversible policy → confirmation spy/tool | rejected |
| CNF-8 | confpolicy-006/106 | policy ownership → confirmation spy/tool | rejected |
| SEC-10 | security-010/110 | typed security input → tool boundary | rejected |
| SEC-12 | security-012/112 | typed security input → tool boundary | rejected |
| SEC-13 | security-013/113 | typed actor input → tool boundary | rejected |
| SEC-14 | security-014/114 | typed actor input → tool boundary | rejected |
| SEC-15 | security-015/115 | typed actor input → tool boundary | rejected |
| SEC-16 | security-016/116 | per-call id/binding → tool boundary | rejected |
| SEC-18 | security-018/118 | typed payload input → tool boundary | rejected |
| SEC-4 | security-004/104 | credential audience → credential boundary | rejected |
| SEC-9 | security-009/109 | args derivation lineage → tool boundary | rejected |
| TLP-11 | confpolicy-001/101 | side effect → confirmation spy/tool | rejected |
| TLP-12 | toolcontract-005/105 | raw tool contract → checker | rejected |
| TLP-25 | profileuntrusted-001/101 | untrusted origin → tool boundary | rejected |
| TLP-26 | toolauthoring-001/101 | raw schema → authoring checker | rejected |
| TLP-27 | toolauthoring-002/102 | raw schema → authoring checker | rejected |
| TLP-28 | toolauthoring-003/103 | raw open-enum schema → checker | rejected |
| TLP-30 | toolauthoring-004/104 | raw schema → authoring checker | rejected |
| TLP-31 | toolauthoring-005/105 | raw tuple schema → checker | rejected |
| TLP-32 | toolauthoring-006/106 | raw exponent schema → checker | rejected |
| TLP-7 | toolcontract-001/101 | raw catalog names → checker | rejected |

Пять SHOULD (`TLP-3`, `TLP-4`, `TLP-8`, `TLP-15`, `TLP-16`) просмотрены как полная выборка:
они не создают обязательства покрытия и остаются честными warnings, а не скрытым GREEN.

### Линза 4 — согласованность фаз и plan truth

Производители/потребители и Required Reads остаются связными. F16 закрыта: фактические PHP-
коммиты `b11cf41`…`e232fc5` отражены в P7.1–P7.10 как `In progress` с якорями; повторный executor
не увидит их как `Not started`. F17 закрыта D164: Q-28 = `Resolved`, граница LOP-26 сохраняет
отмену на уровне действия без нового outcome сценария. Scope P5.1 не ревьюил PHP-код.

### Линза 5 — no-op, дыры и anti-regression

Семь anti-regression классов проходят. Ключевая прежняя дыра «ссылка есть, правило не
различается» теперь закрыта наблюдаемыми carriers: шесть ownership-классов отвергают self-report
shape, exact-51 manifest привязан к source digests, а 11 semantic preflight проверок ловят
confirmation mechanics, exact request type CLR-8, independent mapping registry, wire-byte
boundary, derivation lineage, per-call binding, append conflicts, собственный `prev_hash`, every-
run checker, append accounting и пустой audit sink при reject. F9–F15 закрыты по существу.

Гейты A0/A1/A2/A3 перепройдены; A2/A3 формально зелёные и теперь согласуются с семантической
проверкой. Почему не RED: нет blocker/major finding, нет непривязанного mandatory rule, нет пары,
где sole stimulus является ожидаемым результатом, и все адресные/межправильные мутанты убиты.

### Линза 6 — лексика, термины и цена домена

Legacy-check чист. В замораживаемой ревизии измерено 32 файла с `agentools.dev` и 35 файлов с
`agentools.dev|dev.agentools/`; это реальная цена принятого namespace, а не «два файла».
Терминологического конфликта между 11 документами и glossary не найдено. Публично-юридическое
решение не подменялось аудитом и адресовано P5.3/P5.2.

## Находки раунда 3

Открытых blocker/major/minor/editorial findings нет. F9–F15 закрыты P4.16–P4.21, F16 — plan-
truth reconciliation, F17 — D164/Q-28. Исторические таблицы раундов 1/2 сохранены append-only.

### Вменённая детекция — раунд 3

- Q-16: детекция исполнена — corpus golden выражает fixed input → expected bytes, а три
  некорпусных anchors остаются честно адресованным командным извлечением P6.5/D98.
- Общий свип реестра: Q-28 разрешён D164; Q-30/Q-31 — owner-gates следующего item P5.3;
  Q-5/Q-10 — вход P5.2. Новых потерянных адресатов нет.

## что НЕ проверялось — раунд 3

- Общий conformance runtime runner ещё не существует (P6); проверена выводимость oracle и
  способность каждого carrier убить мутант, а не будущая реализация SDK.
- PHP P7.1–P7.10 не ревьюился в P5.1; проверены только git-факты и plan truth.
- Публичная юридическая очистка имени не выполнялась; безопасная публикационная граница должна
  быть зафиксирована owner-gate до freeze.
- MAY-правила не аудировались; все пять SHOULD просмотрены.

## Перепрогнанные команды и наблюдаемые числа — раунд 3

| Команда | Наблюдение |
|:--|:--|
| observational carriers selftest/check | 6 ownership kinds; exact 51; self-report rejects |
| Gate-B remediation check | manifest v2; 38 behavior + 13 artifact; 51 mutants rejected; 11/11 semantic preflight mutants rejected |
| fixtures check | OK |
| full spec lint + coverage | OK; 5 SHOULD warnings; MUST/MUST NOT 383/383 testable |
| rules index / close schema / legacy | 400 rules; 15 schemas; legacy OK |
| corpus inventory | behavior 214; artifact 89; harness 13; golden registrations 10 |
| domain inventory, revision scope | 32 domain files; 35 domain+vendor files |

Append-only история даёт в целом файле три verdict literals и 18 заголовков линз. Scoped-
проверка блока от `## Раунд 3` до EOF даёт ровно один verdict и шесть линз; удаление прежних RED-
раундов нарушило бы обязательный журнал аудита.
