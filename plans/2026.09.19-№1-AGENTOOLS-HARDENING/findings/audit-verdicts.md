# Проверка аудита `2026-09-19-audit.md`

Проверено 2026-09-19 по текущему дереву. Аудит используется как backlog/evidence, а не как
готовая реализационная спецификация. `RAG:—` ниже означает непосредственную проверку repository
code/docs/tests. External premises вынесены в `research/external-foundations-2026-09-19.md`.

| ID | Утверждение аудита | Verdict | Repository evidence | Владелец |
|:--|:--|:--|:--|:--|
| F01 | Caller управляет confirmation security context/dual control | подтверждено | `ConfirmationProtocol` принимает `now`, `principal`, descriptor, authorizer, proposer и `dualControl`; Dart несёт эквивалентную форму. `RAG:—` | P2 |
| F02 | Spent-token state process-local и replay переживает новый процесс | подтверждено | PHP `$spentTokens` и Dart `_spentTokens` принадлежат instance memory; durable atomic consume отсутствует. `RAG:—` | P3 |
| F03 | Approval/policy/authorization недостаточно связаны с proposal и trusted context | подтверждено как design gap | Current API позволяет передать permissive authorizer/policy inputs на call boundary; контекст authorization узок. `RAG:—` | P2 |
| F04 | Idempotency имеет crash window и не имеет lease/fencing | подтверждено | `IdempotencyStore` выставляет только `resolve()` и `complete()`; acquire/lease/heartbeat/CAS/fence отсутствуют. `RAG:—` | P4 |
| F05 | EventLog не задаёт atomic batch, collision и pagination semantics | подтверждено | Порт имеет single `append(ActionEvent)`, `readFrom`, `lastSeq`; storage-assigned batch/expected version/collision contract отсутствует. `RAG:—` | P5 |
| F06 | Нужен усиленный token envelope | принято с поправкой | Version/alg/kid/purpose/tenant/jti/size/rotation нужны как semantics; JWT не выбран автоматически и не является обязательным результатом. `RAG:—` | P3 |
| F07 | Нужны error taxonomy, JCS и PHP/Dart parity | принято | Cross-language fixtures есть, но единого machine-readable error/parity registry и независимого JCS oracle как release gate нет. `RAG:—` | P6 |
| F08 | Official MCP conformance надо отделить от Agentools conformance | принято | Репозиторий имеет собственные reports/runners; официальный upstream suite требует отдельной applicability matrix и receipts. `RAG:—` | P6 |
| F09 | Публичная API/package поверхность случайна и смешивает core/test doubles | принято с поправкой | Manifests/exports допускают широкую поверхность и in-memory implementations рядом с production contracts; physical split условен, важна наблюдаемая archive/API boundary. `RAG:—` | P7 |
| F10 | Нужны concurrency, mutation, property, fuzz и performance gates | принято с baseline-first условием | Текущие suites не дают полного adversarial proof для новых guarantees; абсолютные performance thresholds без baseline запрещены. `RAG:—` | P8 |
| F11 | CI/supply-chain недостаточны для production release | подтверждено | Имеющиеся workflows не образуют полный least-privilege/pinned dependency matrix + archive/SBOM/provenance/attestation pipeline. `RAG:—` | P9 |
| F12 | Нужны release docs, built-archive consumers и final acceptance | принято | Manifests сейчас private/proprietary; clean consumers from candidate artifacts и единый release dossier отсутствуют. `RAG:—` | P10 |
| F13 | 1970 timestamp/projection bookkeeping требуют чистки | валидно, не P0 | Это observability/maintainability defect, а не основание ослабить четыре подтверждённые P0 security/durability gaps. `RAG:—` | P1/P10 |
| F14 | Предложенные аудитом SQL/interfaces можно реализовать буквально | отклонено как норма | Это полезные sketches, но они не проверены на текущие ports, PHP/Dart parity, migration и storage variants. `RAG:—` | phase design |

## Несущие подтверждения

- `php/src/Confirmation/ConfirmationProtocol.php`: доверенные security inputs входят прямо
  в commit call, а spent-token state локален объекту. `RAG:—`
- Dart confirmation implementation зеркалит local spent-token state и caller-selected policy context. `RAG:—`
- PHP/Dart `IdempotencyStore`: нет lifecycle владения исполнением или fencing. `RAG:—`
- PHP/Dart `EventLog`: нет атомарного batch append, expected-version и content collision semantics. `RAG:—`
- `composer.json`, Dart `pubspec.yaml`, README/LICENSE: текущая identity private/proprietary;
  поэтому public publication нельзя считать механическим финальным шагом. `RAG:—`

## Coverage verdict

Все substantive security, durability, parity, packaging, verification, CI и release findings
аудита имеют owning phase P1–P10. Ни одна находка не переписывает `AGENTOOLS`; PLAN1 владеет
всем будущим remediation и evidence. Детализация обязана заново проверить exact line-level
evidence, потому что этот файл фиксирует состояние дерева только на 2026-09-19.
