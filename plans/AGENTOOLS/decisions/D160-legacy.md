---
id: D160
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**AgenTools — greenfield-пакет: промежуточные draft-fixtures не являются legacy API. P4.10 снова реопенится и нормализует `audit-004/104` против текущей AOB-25: tool description одинакова, единственный input-counterfactual — independently versioned `audit_export.mapping_version`.** Supersedes D156 только в неявном требовании сохранять байты уже собранного candidate, когда они противоречат различающей силе pair; exact 65 single-rule pairs и D159 exact-one predicate сохраняются. **Consequences:** платим ещё одним узким source+bookkeeping циклом и откладываем P4.13; взамен не заводим legacy/migration слой в невыпущенном пакете и убираем ложную вторую причину исхода.

## Почему

Директива владельца 2026-08-04 «пакет с нуля создаётся»; `research/21-p4.10-greenfield-aob25-repair-2026-08.md`; фактический diff `audit-004/104`; `08_audit-observability.md` AOB-25. `RAG:— (repo-grounded)`
