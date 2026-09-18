# 18 — P4.15: typed driver-only contract before W2

**Дата:** 2026-08-04  
**Статус:** design input P4.15  
**Сырьё слоя 1:** сохранённый W2 candidate · `artifacts/P5-gate-b.md` F1/F2/F4 ·
`04_confirmation.md` CNF-2–CNF-8/CNF-13/CNF-21/CNF-23 · `08_audit-observability.md`
AOB-25/AOB-27–AOB-31.

## 1. Наблюдение

P4.9 формально закрыл девять driver-state sections, но `given.policy` несёт только
`allowed_tools`; отсутствуют применимые policy verdict/scope. Не выражены bindings proposal,
token, descriptor digest и fingerprints prepare/commit. Audit carrier умеет задать baggage,
но не сам export adapter, его mapping version, boundary и attributes. В результате broad pairs
можно сделать структурно валидными, однако нельзя доказать CNF-3–6/CNF-13/CNF-21/CNF-23 или
AOB-25/AOB-27–30 единственным меняемым стимулом. `RAG:— (repo-grounded: vector.json;
research/17-p4.10-behavior-carrier-dor-2026-08.md §1; W2 review 2026-08-04)`.

## 2. Граница решения

Новый input остаётся fixture-driver-only и не изменяет MCP wire, published schema либо
нормативный текст. Он не хранит ожидаемый verdict: реализация должна применить typed state и
наблюдаемый oracle. Поля в `journal.payload`, title, free-form context или `expect` не заменяют
input: они не проходят один закрытый contract и создают скрытый stub. `RAG:— (repo-grounded:
fixtures/2026-08/README.md §2; P4.9 Code Guidance)`.

## 3. Точный минимальный контракт

| Место | Поля | Различает |
|:--|:--|:--|
| `given.policy.rules[]` | `scope: system|tenant|user|tool`, `verdict: deny|ask|allow` | CNF-3–6 и TLP-11 без неявного precedence/scope |
| `given.driver_state.confirmation` | `proposal_id`, `proposal_version`, `token_id`, `covered_principal_id`, `prepared_descriptor_digest`, `current_descriptor_digest`, `prepared_args_fingerprint`, `commit_args_fingerprint` | CNF-13/CNF-21/CNF-23 и token/proposal bindings |
| `given.driver_state.audit_export` | `adapter_id`, `mapping_version`, `boundary: external`, `attributes[]`, `audit_fields[]` | AOB-25/AOB-27–30; attributes остаются adapter-side data |

Все объекты closed; массивы содержат непустые строки. `attributes` и `audit_fields` — вход
наблюдаемого экспорта/аудита, а не claim о conformance. AOB-26 остаётся статическим P4.12.
`RAG:— (repo-grounded: 04_confirmation.md:56-80,104-152; 08_audit-observability.md:201-236)`.

## 4. Последовательность

1. P4.15 добавляет schema, README и real-schema selftest, коммитит source и bookkeeping.
2. Workflow W2 требует terminal P4.15; его plan files чисты, candidate снова состоит только
   из P4.10–P4.12 paths.
3. Sharded P4.10 fix нормализует existing broad pairs через typed inputs; не меняет contract
   локально и не получает второй fix-loop.

Это сохраняет D155 resume и D156 exact 65/65; изменение contract после W2 review без отдельного
item было бы обходом их fail-closed границы. `RAG:— (repo-grounded: D155–D157; workflow W2)`.
