# P1 cross-contract coverage gate

**Evidence date:** 2026-09-20  
**Gate owner:** P1.5  
**Contracts:** `threat-model-v1.md` · `compatibility-support-v1.md` · `hardening-guarantees-v1.md` · `resource-envelope-v1.md`

Every row has exactly one downstream owner and one proof class. **Unowned: 0.**

## Audit findings (F01–F14)

| ID | Owner | Proof class | Guarantee / NG | Resource | Migration |
|:--|:--|:--|:--|:--|:--|
| F01 | P2 | negative integration | G-CNF-001 | RL-010 | M-001 |
| F02 | P3 | replay / durable consume | G-TKN-003, NG-MEM-001 | RL-024 | M-002 |
| F03 | P2 | authorizer binding | G-CNF-001 | — | M-003 |
| F04 | P4 | crash + fence | G-IDC-002, NG-EXT-001 | RL-015–RL-016, RL-021 | M-004 |
| F05 | P5 | storage CAS / pagination | G-EVT-002 | RL-011–RL-014, RL-025 | M-005 |
| F06 | P3 | envelope vectors | G-TKN-001 | RL-008–RL-009, RL-017–RL-019 | M-006 |
| F07 | P6 | registry + JCS oracle | G-SEM-001, G-JCS-001 | BW-JCS-001 | M-007 |
| F08 | P6 | applicability matrix | G-SCP-001, NG-MCP-001 | — | M-008 |
| F09 | P7 | manifest + archive | G-PKG-001 | — | M-009 |
| F10 | P8 | adversarial corpus | G-ADV-001, G-SCN-001, G-OPS-001 | RL-001–RL-007, BW-* | M-010 |
| F11 | P9 | attested CI | G-CI-001 | — | M-011 |
| F12 | P10 | acceptance dossier | G-REL-001 | — | M-012 |
| F13 | P5 | projection hygiene tests | G-OBS-001 | — | M-013 |
| F14 | phase design | none (rejected sketch) | NG-DES-001 | — | — |

## Threats (TM-001–TM-014)

| ID | Owner | Mapped in guarantees v1 |
|:--|:--|:--|
| TM-001 | P2 | G-CNF-001, G-CNF-002 |
| TM-002 | P3 | G-TKN-002, G-TKN-003, NG-MEM-001 |
| TM-003 | P2 | G-CNF-001 |
| TM-004 | P4 | G-IDC-001, G-IDC-002, NG-EXT-001 |
| TM-005 | P5 | G-EVT-001, G-EVT-002 |
| TM-006 | P3 | G-TKN-001 |
| TM-007 | P6 | G-SEM-001, G-JCS-001 |
| TM-008 | P6 | G-SCP-001, NG-MCP-001 |
| TM-009 | P7 | G-PKG-001 |
| TM-010 | P8 | G-ADV-001, G-SCN-001, G-OPS-001, NG-AVL-001 |
| TM-011 | P9 | G-CI-001 |
| TM-012 | P10 | G-REL-001 |
| TM-013 | P5 | G-OBS-001 |
| TM-014 | phase design | NG-DES-001 |

## Migrations (M-001–M-017)

All rows in `findings/P1-migration-inventory.md` map to the F/TM owners above; M-014–M-017 are compatibility bookkeeping (P9/P10/owner) with NG-SUP-001 where no release lane is claimed.

## Guarantees and non-guarantees

| Class | Count | Owner spread |
|:--|:--|:--|
| G-* | 20 | P2–P10 |
| NG-* | 9 | P2–P10 + phase design |

## Resource limits (RL-001–RL-025)

| Status bucket | Count | Owner |
|:--|:--|:--|
| implemented (both SDKs) | 6 | P8 boundary tests |
| implemented (one SDK) / divergent | 1 (RL-009) | P3 |
| missing / unbounded | 18 | Named P2–P8 owners per registry |

## Phase notification map (P2–P10)

| Phase | Consumes | Must cite on entry |
|:--|:--|:--|
| P2 | TM-001/003, G-CNF-*, RL-010 | `confirmation-security-v1` + G-CNF rows |
| P3 | TM-002/006, G-TKN-*, RL-008–019, RL-024 | `token-replay-v1` + RL token rows |
| P4 | TM-004, G-IDC-*, RL-015–016, RL-021 | `idempotency-lease-v1` |
| P5 | TM-005/013, G-EVT-*, G-OBS-001, RL-011–014, RL-023, RL-025 | `event-store-v1` |
| P6 | TM-007/008, G-SEM/JCS/SCP | semantic manifest + BW-JCS-001 |
| P7 | TM-009, G-PKG-001, NG-MEM-001 | `package-boundary-v1` |
| P8 | TM-010, G-ADV/SCN/OPS, RL-001–007, BW-* | `adversarial-verification-v1` |
| P9 | TM-011, G-CI-001, NG-SUP-001 | `ci-supply-chain-v1` |
| P10 | TM-012, G-REL-001 | `release-acceptance-v1` |

## Gate verdict

**P1 cross-contract coverage: PASS** — no unowned F01–F14, TM, migration, guarantee, or RL row.
