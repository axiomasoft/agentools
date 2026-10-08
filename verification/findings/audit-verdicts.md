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
| F13 | 1970 timestamp/projection bookkeeping требуют чистки | валидно, не P0 | Это observability/maintainability defect, а не основание ослабить четыре подтверждённые P0 security/durability gaps. `RAG:—` | P5 |
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

## Independent whole-plan design audit — 2026-09-21

**Verdict: RED.** Это первый whole-plan audit; предшествующая история содержит 12
`plan-design`-записей (master, P1–P10 и roadmap), но ни одного terminal whole-plan
`plan-audit`. Между design и этим аудитом журнал уже закрыл 45 уникальных product items,
хотя D2 и P0.1 запрещали product execution до GREEN audit. Историческое исполнение не
аннулируется и не переписывается, но P0.1 нельзя честно закрыть GREEN.

### Material findings

| ID | Severity | Finding and evidence | Owning continuation |
|:--|:--|:--|:--|
| WPA-01 | critical | D2/P0.1 pre-execution invariant нарушен: 45 P1–P10 items имеют `closed-green`, тогда как P0.1 остаётся `Not started` и whole-plan audit отсутствовал. Это необратимое lifecycle deviation, не доказательство product RED само по себе. | Control-plane reconciliation; не повторять design. |
| WPA-02 | critical | P10.4/P10.5 закрыты GREEN вопреки собственным Validation: `P10-release-dossier.json` имеет `private_ready=red` и 3 private blockers, `P10-private-rc-acceptance.json` имеет `verdict=red`. P10.5 требует GREEN private readiness. | `run-items: task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P10.1`, затем affected P10.4–P10.5. |
| WPA-03 | high | Acceptance ownership/fixed-point неполон: AV-019 уже GREEN в `findings/P9-private-rc.md`, но P10 matrix оставляет его `baseline_pending`/`upstream_owner=deferred`; G-REL-001 остаётся private-blocking precursor для собственного P10 dossier; G-OBS-001 имел составного владельца `P1/P10`. | **Repaired 2026-09-21:** G-OBS-001/TM-013/M-013 → P5; NG-SUP-001 → P9 (P1.4–P1.5); AV-019 → `verified`/owner P9 in `P10-acceptance-matrix.json` (P10.1). G-REL-001 remains intentional `baseline_pending` until dossier recompute (P10.4). |
| WPA-04 | high | Инвариант одного владельца нарушен в normative carriers: `G-OBS-001` и `M-013` принадлежат `P1/P10`; resource rows RL-010/RL-020/RL-021/RL-022 имеют составных владельцев, хотя P1.4/P1.5 требуют ровно одного owner. | **Repaired 2026-09-21 (P1.4–P1.5 plan-run):** single owners in guarantees/resource/coverage; validators reject composite RL/G/NG owners. |
| WPA-05 | high | P0.1 routing расходится в трёх carriers: Routing row = `frontier/high manual`, §3.3 = `plan-audit frontier/xhigh`, handoff = `plan-run implementation/medium`. Текущий handoff не является допустимым design-audit route. | P0.1/control-plane bookkeeping repair; канон для audit — `task:plan-audit ... design`, `frontier/xhigh`. |
| WPA-06 | high | После канонической регенерации views `plan-lint --gate all` = 42 ERROR / 1 WARN: 14 schema-invalid journal records, 6 stale/invalid terminal spec digests, 3 missing terminal-evidence records, 6 missing write-site carriers, 12 forbidden reads of terminal phase specs, missing/invalid read-budget contract и один false-positive clarification marker. | Journal/evidence/read-path repair through owning Task mechanisms; append-only history не переписывать без explicit owner exception mechanism. |
| WPA-07 | medium | P6/P8 known deviations (`CRP-JCS-10K`, `CRP-SEM-213`, partial successor behavior corpus, mutation exclusion) видимы, но не являются причиной private RED, пока contract dispositions остаются non-blocking и точными. | Preserve as explicit residuals; do not silently promote to proved. |

### P1–P10 traversal verdict

| Phase | Audit result |
|:--|:--|
| P1 | **ATTENTION (repaired ownership):** composite G/NG/RL owners normalized 2026-09-21; benchmark BW rows may still name calibration co-owners. |
| P2 | ATTENTION: design boundary coherent; terminal evidence/write-site carriers invalid in journal. |
| P3 | GREEN design semantics; migration is explicit hard cutover, durable consume remains separately owned. |
| P4 | GREEN design semantics; fenced lease/capability and non-guarantee boundary are explicit. |
| P5 | GREEN design semantics; atomic append/collision/pagination ownership and SQLite limitation are explicit. |
| P6 | ATTENTION: semantic/MCP/JCS ownership is separated, but terminal evidence and declared deviations prevent an unqualified proof claim. |
| P7 | ATTENTION: package ownership is explicit; journal has duplicate P7.1–P7.3 closures and recorded route deviation. |
| P8 | ATTENTION: aggregate namespaces are separated; declared unproved corpus rows must remain residuals. |
| P9 | RED for downstream mapping: P9 reports AV-019 GREEN, P10 imports it as deferred/baseline-pending; journal schema is incomplete. |
| P10 | RED: machine acceptance is RED while P10.4/P10.5 lifecycle state says GREEN. |

### Release-gate evidence sampled during audit

Contract validators for hardening guarantees, confirmation, token replay, idempotency and event
store returned 0. CI workflow policy/matrix, release-candidate verification, SBOM verification,
consumer validation and dossier/private-release schema validation also returned 0. These checks
prove structural integrity, not release acceptance: the authoritative dossier still reports
`private_ready=red`, `public_eligible=red`, `public_authorized=false`.

### Closure decision

P0.1 remains open. GREEN closure is forbidden until WPA-02/WPA-03/WPA-04 are repaired,
terminal evidence is canonical, generated carriers are refreshed and
`plan-lint --gate all` returns 0 errors and 0 warnings. No product code was changed by this audit.

## Independent repeat whole-plan design audit — 2026-09-21

**Verdict: GREEN.** The independent reviewer rechecked the remediation delta after the
owner-gated D17 journal migration and the WPA-02 P10 acceptance repair. No material design
finding remains open for P0.1.

### Resolved findings

| ID | Resolution evidence |
|:--|:--|
| WPA-01 / WPA-06 | Historical lifecycle/schema defects are preserved through D17's exact preimage, digest-bound manifest and receipt; owning `plan-lint --gate all` reports 0 errors and 0 warnings. |
| WPA-02 / WPA-03 | P10 acceptance matrix and dossier now report `private_ready=green`; private blocker count is zero; private RC acceptance is GREEN. `G-OBS-001` and `G-REL-001` are verified against their owning P5/P10 evidence. |
| WPA-04 | Guarantee/resource contracts and validators retain one exact owner per normative row. |
| WPA-05 | P0.1 runs on the attested `frontier/high` manual route; the independent repeat audit ran separately on `frontier/xhigh`. |
| WPA-07 | Declared P6/P8 limitations remain explicit residual/public rows and were not promoted into unsupported claims. |

### Repeat-audit evidence

- `python3 tools/release_acceptance.py validate` → exit 0; private GREEN, public RED,
  `public_authorized=false`.
- `python3 tools/release_dossier.py validate` → exit 0; zero private blockers and 18
  explicit public blockers.
- `python3 tools/private_release_gate.py validate` and `fixtures` → exit 0; private
  acceptance GREEN and the G-RELEASE packet remains non-authorizing.
- `python3 tests/tools/test_release_acceptance.py` → 9 tests OK;
  `test_release_dossier.py` → 5 tests OK; `test_private_release_gate.py` → 4 tests OK.
- owning Task `plan-lint --gate all` → 0 errors, 0 warnings.

Evidence ID: `plan-audit:PLAN1:design:2026-09-21:green:wpa02-remediated`.
Public release authority was not granted or exercised.
