---
id: D169
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Gate B round 3 не считается начатым, пока semantic preflight нашёл остаточные self-report/magic gaps P4.19; P4 адресно реопенится одним P4.20.** Исправляются confirmation facts TLP-11/CNF-6/8, protocol operations CLR-8, registered mapping AOB-25, wire-byte boundary AOB-31, provenance derivation SEC-9, per-call binding SEC-16, append-adapter ALC-20/22/23, hash composition ALC-36 и per-run CFH-24. **Consequences:** history P4.16–P4.19 сохраняется, manifest/A3 переиздаются; owner-authorized Gate B расходуется только после terminal P4.20.

## Почему

Read-only semantic preflight exact 51 против нормативных строк; owner directive «формальный линтер без семантики не успех». `RAG:— (owner decision + repo-grounded)`
