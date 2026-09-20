# P8.1 risk/proof coverage

**Date:** 2026-09-20  
**Contract:** `root/contracts/adversarial-verification-v1.md`  
**Manifest:** `fixtures/hardening/adversarial/manifest.v1.json`  
**Validator:** `python3 tools/validate_adversarial_contract.py`

Exact AV/disposition sets are owned by the manifest. This report records coverage
rationale and residuals only.

## Coverage

Every terminal `G-*`, `NG-*` and `RL-*` plus recorded P2–P7 findings residuals has
exactly one disposition. Critical confirmation, token, fencing, event, JCS, bounds
and package rows each name a killable mutant (or structural catalog oracle for
`G-ADV-001`) and a release-candidate carrier.

Independent oracles:

- P6 JCS bytes under `conformance/oracles/jcs/`
- P6 semantic manifest
- package-surface roster
- frozen hardening fixtures for confirmation/token/lease/event

## Gaps kept visible

| Subject | Disposition | Why it is not guessed |
|:--|:--|:--|
| PostgreSQL crash/interleaving (`SCH-*`) | declared, executed by P8.4 | isolated processes and cleanup are a separate safety boundary |
| Missing numeric resource ceilings (`RL-008`–`RL-025` except implemented expression/baggage) | deferred to P8.5 / owning phase | envelope already records `missing`; P8.1 does not invent limits |
| JCS 10k ES6 corpus | `CRP-JCS-10K` on AV-007, extended budget | P6.3 deferred this checksum; generator identity is declared now |
| Full 2026-09 behavior 213 | `CRP-SEM-213` on AV-015 | residual from P6.5; not silently marked proved |
| CI PostgreSQL matrix / Dart live driver | deferred P9 | P8 does not edit workflows |
| Publication/tag/D5 | deferred P10 / owner | G-RELEASE remains unauthorized |

## Negative validator proofs

`python3 tools/validate_adversarial_contract.py test` turns red for duplicate AV
id, missing owner, critical row without independent oracle/red proof, unbounded
random metadata, unknown tier, and orphan mutant id.
